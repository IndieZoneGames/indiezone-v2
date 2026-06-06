<?php

/**
 * IndieZone — Widget de Jogos Similares
 * =======================================
 * Inclua no game_details.php logo antes de </main>:
 *
 * <?php require_once __DIR__ . '/../../scripts/widget/similares.php'; ?>
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

$base_url = defined('APP_URL') ? APP_URL : '/indiezone-main/public';
?>

<link rel="stylesheet" href="<?= $base_url ?>/assets/css/similares.css">

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
          ? $base_url . '/pages/game_details.php?id=' . (int)$j['game_id']
          : '#';

        $capa_raw = $j['capa_url'] ?? '';
        $capa = !empty($capa_raw)
          ? ((strpos($capa_raw, 'http') === 0)
            ? htmlspecialchars($capa_raw)
            : $base_url . htmlspecialchars($capa_raw))
          : $base_url . '/assets/img/Placeholder_Padrao.png';

        // Preço — campo 'preco'
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
              onerror="this.src='<?= $base_url ?>/assets/img/placeholders/PlaceHolder_acao.svg'">
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

<script>
  function izScroll(btn, dir) {
    const trilho = btn.closest('.iz-carrossel').querySelector('.iz-trilho');
    trilho.scrollBy({
      left: dir * trilho.clientWidth * 0.75,
      behavior: 'smooth'
    });
  }
</script>