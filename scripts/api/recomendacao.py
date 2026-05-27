"""
IndieZone — API de Recomendação v4.1
=====================================
Modelo retreinado com dados do banco IndieZone.
Recomenda jogos da própria base.

Uso:
    cd scripts/api
    uvicorn recomendacao:app --host 0.0.0.0 --port 8000 --reload
"""

import os
import json
import math
import pickle
import logging
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
import pymysql
from dotenv import load_dotenv
from fastapi import FastAPI, HTTPException, Query
from fastapi.middleware.cors import CORSMiddleware
from sklearn.preprocessing import MinMaxScaler

load_dotenv()
logging.basicConfig(level=logging.INFO)
log = logging.getLogger("indiezone.api")

MODEL_DIR = Path(__file__).parent.parent / "modelo"

app = FastAPI(title="IndieZone Recomendação", version="4.1.0")
app.add_middleware(
    CORSMiddleware,
    allow_origins=[os.getenv("FRONTEND_ORIGIN", "*")],
    allow_methods=["GET"],
    allow_headers=["*"],
)

# ─────────────────────────────────────────────
# Carrega artefatos
# ─────────────────────────────────────────────
log.info("Carregando modelo IndieZone...")

knn_model          = joblib.load(MODEL_DIR / "knn_model.pkl")
game_to_index      = pickle.loads((MODEL_DIR / "game_index.pkl").read_bytes())
interaction_matrix = pickle.loads((MODEL_DIR / "interaction_matrix.pkl").read_bytes())
tfidf_vectorizer   = joblib.load(MODEL_DIR / "tfidf_vectorizer.pkl")
df_modelo          = pd.read_parquet(MODEL_DIR / "df.parquet")
meta               = json.loads((MODEL_DIR / "meta.json").read_text())

# Recalcula normalização se não vier no parquet
if "_ratio_norm" not in df_modelo.columns:
    df_modelo["log_total_reviews"] = np.log1p(df_modelo["total_reviews"]).astype("float32")
    _sr = MinMaxScaler().fit(df_modelo[["positive_ratio"]])
    _sv = MinMaxScaler().fit(df_modelo[["log_total_reviews"]])
    df_modelo["_ratio_norm"]   = _sr.transform(df_modelo[["positive_ratio"]]).flatten()
    df_modelo["_reviews_norm"] = _sv.transform(df_modelo[["log_total_reviews"]]).flatten()

app_id_to_pos = {int(row["app_id"]): i for i, row in df_modelo.iterrows()}

log.info(f"Modelo pronto — {len(df_modelo):,} jogos IndieZone.")

W_CONTENT  = meta["pesos_score"]["w_content"]
W_APPROVAL = meta["pesos_score"]["w_approval"]
W_REVIEWS  = meta["pesos_score"]["w_reviews"]
FATOR      = meta["fator_rigor"]

# ─────────────────────────────────────────────
# Helpers
# ─────────────────────────────────────────────
def limpar_nan(obj):
    """Recursivamente substitui nan/inf por None — compatível com JSON."""
    if isinstance(obj, dict):
        return {k: limpar_nan(v) for k, v in obj.items()}
    if isinstance(obj, list):
        return [limpar_nan(v) for v in obj]
    if isinstance(obj, float) and (math.isnan(obj) or math.isinf(obj)):
        return None
    return obj

def safe(val, default=None):
    """Converte nan/None para default de forma segura."""
    if val is None:
        return default
    try:
        if pd.isna(val):
            return default
    except (TypeError, ValueError):
        pass
    return val

def get_db():
    return pymysql.connect(
        host        = os.getenv("DB_HOST", "localhost"),
        port        = int(os.getenv("DB_PORT", 3306)),
        user        = os.getenv("DB_USER", "root"),
        password    = os.getenv("DB_PASS", ""),
        database    = os.getenv("DB_NAME", "indiezone"),
        charset     = "utf8mb4",
        cursorclass = pymysql.cursors.DictCursor,
        connect_timeout = 5,
        ssl         = {"ssl_disabled": False, "check_hostname": False},
    )

def _comprimir(x: np.ndarray, fator: float) -> np.ndarray:
    return 1 - (1 - np.clip(x, 0, 1)) ** (1 / fator)

def _construir_soup(jogo: dict) -> str:
    tags   = str(jogo.get("tags") or "").replace(",", " ").replace(";", " ").lower()
    genres = str(jogo.get("genres") or "").replace(";", " ").lower()
    return f"{tags} {tags} {tags} {genres}".strip()

def _recomendar(game_id: int, titulo: str, tags: str, genres: str, n: int):
    if titulo in game_to_index:
        idx = game_to_index[titulo]
        if isinstance(idx, pd.Series):
            idx = idx.iloc[0]

        distances, indices = knn_model.kneighbors(
            interaction_matrix[idx],
            n_neighbors=min(n * 8 + 1, len(df_modelo))
        )
        idx_arr = indices.flatten()
        sim_arr = 1 - distances.flatten()
        mask    = idx_arr != idx
        idx_arr = idx_arr[mask]
        sim_arr = sim_arr[mask]
    else:
        soup  = _construir_soup({"tags": tags, "genres": genres})
        vetor = tfidf_vectorizer.transform([soup])
        distances, indices = knn_model.kneighbors(
            vetor,
            n_neighbors=min(n * 8, len(df_modelo))
        )
        idx_arr = indices.flatten()
        sim_arr = 1 - distances.flatten()

    df_rec = df_modelo.iloc[idx_arr].copy()
    df_rec["sim_content"] = _comprimir(sim_arr, FATOR)
    df_rec["score"] = (
        W_CONTENT  * df_rec["sim_content"] +
        W_APPROVAL * df_rec["_ratio_norm"] +
        W_REVIEWS  * df_rec["_reviews_norm"]
    )

    resultado = []
    for _, row in df_rec.sort_values("score", ascending=False).head(n).iterrows():
        rec_id   = int(row["app_id"]) if pd.notna(row.get("app_id")) else None
        capa_url = safe(row.get("cover_image_url"))

        resultado.append({
            "game_id":   rec_id,
            "titulo":    safe(row["title"], ""),
            "developer": safe(row["developer"], ""),
            "genres":    safe(row.get("genres"), ""),
            "preco":     float(safe(row["price"], 0.0)),
            "score":     round(float(safe(row["score"], 0.0)), 4),
            "capa_url":  capa_url,
            "url":       f"/pages/game_details.php?id={rec_id}" if rec_id else None,
        })

    return resultado

# ─────────────────────────────────────────────
# Endpoints
# ─────────────────────────────────────────────
@app.get("/recomendar/{game_id}")
def recomendar(game_id: int, n: int = Query(default=8, ge=1, le=20)):
    conn = None
    try:
        conn = get_db()
        with conn.cursor() as cur:
            cur.execute("""
                SELECT g.title, g.tags,
                       GROUP_CONCAT(gen.slug ORDER BY gen.slug SEPARATOR ';') AS genres
                FROM games g
                LEFT JOIN game_genres gg ON gg.game_id = g.game_id
                LEFT JOIN genres gen ON gen.genre_id = gg.genre_id
                WHERE g.game_id = %s AND g.status = 'published'
                GROUP BY g.game_id, g.title, g.tags
                LIMIT 1
            """, (game_id,))
            row = cur.fetchone()
    except Exception as e:
        log.error(f"Erro banco: {e}")
        raise HTTPException(503, "Banco indisponível.")
    finally:
        if conn:
            conn.close()

    if not row:
        raise HTTPException(404, "Jogo não encontrado.")

    recs = _recomendar(
        game_id = game_id,
        titulo  = row["title"],
        tags    = row.get("tags") or "",
        genres  = row.get("genres") or "",
        n       = n,
    )

    return limpar_nan({"jogo_alvo": row["title"], "recomendacoes": recs})


@app.get("/debug/{game_id}")
def debug(game_id: int):
    conn = None
    try:
        conn = get_db()
        with conn.cursor() as cur:
            cur.execute("""
                SELECT g.title, g.tags,
                       GROUP_CONCAT(gen.slug SEPARATOR ';') AS genres
                FROM games g
                LEFT JOIN game_genres gg ON gg.game_id = g.game_id
                LEFT JOIN genres gen ON gen.genre_id = gg.genre_id
                WHERE g.game_id = %s
                GROUP BY g.game_id, g.title, g.tags
                LIMIT 1
            """, (game_id,))
            row = cur.fetchone()
    finally:
        if conn:
            conn.close()

    if not row:
        raise HTTPException(404, "Jogo não encontrado.")

    soup     = _construir_soup({"tags": row.get("tags"), "genres": row.get("genres")})
    no_model = row["title"] in game_to_index

    return limpar_nan({
        "titulo":    row["title"],
        "no_modelo": no_model,
        "tags":      row.get("tags"),
        "genres":    row.get("genres"),
        "soup":      soup,
    })


@app.get("/health")
def health():
    return {
        "status":          "ok",
        "jogos_no_modelo": len(df_modelo),
        "versao":          "4.1.0 — corpus IndieZone",
    }