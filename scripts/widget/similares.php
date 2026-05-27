<?php

/**
 * IndieZone — Widget de Jogos Similares
 * =======================================
 * Inclua no game_details.php logo antes de </main>:
 *
 *   <?php require_once __DIR__ . '/../../scripts/widget/similares.php'; ?>
 *
 * Requer que $game_id esteja definido na página que o inclui.
 * Os jogos recomendados vêm do banco IndieZone — modelo interno.
 */

$_IZ_API   = 'http://localhost:8000';
$_IZ_N     = 15;
$_IZ_CACHE = 3600;

function iz_buscar_similares(int $game_id, string $api, int $n, int $ttl): array
{
  $cache_dir  = sys_get_temp_dir() . '/iz_rec';
  $cache_file = "$cache_dir/game_{$game_id}.json";

  if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $ttl) {
    $cached = json_decode(file_get_contents($cache_file), true);
    if (!empty($cached)) return $cached;
  }

  $ctx  = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
  $json = @file_get_contents("$api/recomendar/$game_id?n=$n", false, $ctx);

  if (!$json) return [];

  $dados = json_decode($json, true);
  $recs  = $dados['recomendacoes'] ?? [];

  if (!is_dir($cache_dir)) @mkdir($cache_dir, 0755, true);
  if (!empty($recs)) @file_put_contents($cache_file, json_encode($recs));

  return $recs;
}

$_IZ_RECS = isset($game_id)
  ? iz_buscar_similares((int)$game_id, $_IZ_API, $_IZ_N, $_IZ_CACHE)
  : [];

if (empty($_IZ_RECS)) return;
?>

<section class="iz-similares">
  <h2 class="iz-similares__titulo">
    <span aria-hidden="true">◈</span> Jogos Similares
  </h2>

  <div class="iz-carrossel">
    <button class="iz-nav iz-nav--prev" onclick="izScroll(this,-1)" aria-label="Anterior">&#8249;</button>

    <div class="iz-trilho" id="iz-trilho-<?= (int)$game_id ?>">
      <?php foreach ($_IZ_RECS as $i => $j):
        // URL interna — usa game_id se disponível, senão '#'
        $href = !empty($j['game_id'])
          ? '/indiezone-main/public/pages/game_details.php?id=' . (int)$j['game_id']
          : '#';

        $base_url = defined('APP_URL') ? APP_URL : '';
        $capa_raw = $j['capa_url'] ?? '';
        $capa = !empty($capa_raw)
          ? ((strpos($capa_raw, 'http') === 0)
            ? htmlspecialchars($capa_raw)
            : $base_url . htmlspecialchars($capa_raw))
          : $base_url . '/assets/img/Placeholder_Padrao.png';

        // Preço — campo 'preco' (não preco_usd)
        $preco = (float)($j['preco'] ?? 0);
      ?>
        <a
          class="iz-card"
          href="<?= $href ?>"
          title="<?= htmlspecialchars($j['titulo']) ?>"
          style="--iz-i:<?= $i ?>">
          <div class="iz-card__img">
            <img
              src="<?= $capa ?>"
              alt="<?= htmlspecialchars($j['titulo']) ?>"
              loading="lazy"
              onerror="this.src='/indiezone-main/public/assets/img/placeholders/PlaceHolder_acao.svg'">
            <span class="iz-card__badge"><?= number_format($j['score'] * 100, 0) ?>%</span>
          </div>

          <div class="iz-card__body">
            <p class="iz-card__name"><?= htmlspecialchars($j['titulo']) ?></p>
            <p class="iz-card__dev"><?= htmlspecialchars($j['developer'] ?? '') ?></p>
            <p class="iz-card__price">
              <?php if ($preco == 0): ?>
                <span class="iz-card__price--gratis">Grátis</span>
              <?php else: ?>
                R$&nbsp;<?= number_format($preco, 2, ',', '.') ?>
              <?php endif; ?>
            </p>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <button class="iz-nav iz-nav--next" onclick="izScroll(this,1)" aria-label="Próximo">&#8250;</button>
  </div>

  <p class="iz-similares__fonte">
    Recomendações baseadas em similaridade de conteúdo
  </p>
</section>

<style>
  .iz-similares {
    --ac: #6c63ff;
    --bg: #1e293b;
    --bd: #334155;
    --tx: #e2e8f0;
    --mt: #94a3b8;
    --green: #4ade80;

    margin: 24px 0 0;
    padding-top: 16px;
    padding-bottom: 4px;
    border-top: 1px solid var(--bd);
  }

  .iz-similares__titulo {
    display: flex;
    align-items: center;
    gap: .45rem;
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--tx);
    margin: 0 0 1.25rem;
  }

  .iz-similares__titulo span {
    color: var(--ac);
  }

  .iz-carrossel {
    display: flex;
    align-items: center;
    gap: 6px;
  }

  .iz-trilho {
    display: flex;
    gap: 12px;
    overflow-x: auto;
    scroll-behavior: smooth;
    scrollbar-width: none;
    -ms-overflow-style: none;
    padding: 4px 2px 14px;
    flex: 1;
  }

  .iz-trilho::-webkit-scrollbar {
    display: none;
  }

  .iz-nav {
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 1px solid var(--bd);
    background: var(--bg);
    color: var(--tx);
    font-size: 1.3rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background .15s, border-color .15s;
  }

  .iz-nav:hover {
    background: var(--ac);
    border-color: var(--ac);
  }

  .iz-card {
    flex: 0 0 240px;
    border-radius: 8px;
    border: 1px solid var(--bd);
    background: var(--bg);
    overflow: hidden;
    text-decoration: none;
    color: var(--tx);
    transition: transform .18s, border-color .18s, box-shadow .18s;
    animation: izUp .3s ease calc(var(--iz-i, 0) * 50ms) both;
  }

  .iz-card:hover {
    transform: translateY(-4px);
    border-color: var(--ac);
    box-shadow: 0 6px 20px rgba(108, 99, 255, .18);
  }

  @keyframes izUp {
    from {
      opacity: 0;
      transform: translateY(10px)
    }

    to {
      opacity: 1;
      transform: translateY(0)
    }
  }

  .iz-card__img {
    position: relative;
    height: 160px;
    background: #0f172a;
  }

  .iz-card__img img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  .iz-card__badge {
    position: absolute;
    top: 6px;
    right: 6px;
    background: var(--ac);
    color: #fff;
    font-size: .7rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 20px;
    opacity: 0;
    transition: opacity .18s;
  }

  .iz-card:hover .iz-card__badge {
    opacity: 1;
  }

  .iz-card__body {
    padding: 8px 10px 10px;
  }

  .iz-card__name {
    font-size: .8rem;
    font-weight: 600;
    margin: 0 0 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .iz-card__dev {
    font-size: .7rem;
    color: var(--mt);
    margin: 0 0 6px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .iz-card__price {
    font-size: .78rem;
    font-weight: 600;
    margin: 0;
  }

  .iz-card__price--gratis {
    color: var(--green);
  }

  .iz-similares__fonte {
    margin: 4px 0 2px;
    font-size: .7rem;
    color: #475569;
  }
</style>

<script>
  function izScroll(btn, dir) {
    const trilho = btn.closest('.iz-carrossel').querySelector('.iz-trilho');
    trilho.scrollBy({
      left: dir * trilho.clientWidth * 0.75,
      behavior: 'smooth'
    });
  }
</script>