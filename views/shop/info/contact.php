<?php
/** One main channel in colour, the others outlined, then the shop's details. @var array $s */
$ar = lang() === 'ar';
$shop = $s['shop'];
$raw = trim((string) $s['contact']['messenger']);
$messenger = '';
if ($raw !== '') {
    $m = preg_match('#^(?:https?://)?(?:www\.|m\.|web\.)?(?:m\.me|facebook\.com|fb\.com|messenger\.com/t)/([^/?\#]+)#i', $raw, $mm) ? $mm[1] : $raw;
    $not = ['login', 'accounts', 'explore', 'sharer', 'sharer.php', 'share', 'dialog', 'home.php', 'watch', 'groups', 'hashtag', 'tag', 'search'];
    $messenger = preg_match('/^[A-Za-z0-9.]{5,50}$/', $m) && !preg_match('/^\d+$/', $m) && !in_array(strtolower($m), $not, true) ? $m : '';
}
$facebook = profile_url('facebook', (string) $shop['facebook']);
$tel = Phone::normalize((string) $shop['phone']);
$wa = (string) $s['contact']['whatsapp'];
ob_start();
?>
<div class="flex flex-col gap-3">
<?php if ($wa !== '') : ?><a href="https://wa.me/216<?= e($wa) ?>?text=<?= rawurlencode(t('trust.waHello')) ?>" target="_blank" rel="noopener" data-contact="whatsapp" data-where="contact" class="flex min-h-14 items-center justify-center gap-2.5 rounded-full bg-wa px-6 text-[17px] font-bold text-white"><?= icon('WhatsApp', 'h-6 w-6') ?><?= e($ar ? 'ابعثلنا على واتساب' : 'Écrivez-nous sur WhatsApp') ?></a><?php endif ?>
<?php if ($tel) : ?><a href="tel:+216<?= e($tel) ?>" data-contact="phone" data-where="contact" class="flex min-h-14 items-center justify-center gap-2.5 rounded-full border border-ink/60 bg-white px-6 text-[17px] font-bold text-ink hover:bg-sand"><?= icon('Phone', 'h-5 w-5') ?><span class="ltr-nums"><?= e(Phone::format($tel)) ?></span></a><?php endif ?>
<?php if ($messenger !== '') : ?><a href="https://m.me/<?= e($messenger) ?>" target="_blank" rel="noopener" data-contact="messenger" data-where="contact" class="flex min-h-14 items-center justify-center gap-2.5 rounded-full border border-line bg-white px-6 text-[17px] font-bold text-ink hover:bg-sand"><?= icon('Messenger', 'h-5 w-5 text-[#0866ff]') ?>Messenger</a><?php endif ?>
</div>
<?php if ($shop['email'] || $shop['address'] || $shop['legalName'] || $shop['legalId'] || $facebook) : ?>
<dl class="mt-3 divide-y divide-line rounded-[20px] border border-line">
<?php if ($shop['legalName'] || $shop['legalId']) : ?><div class="px-5 py-4"><dt class="text-[15px] text-muted"><?= e($ar ? 'البائع' : 'Vendeur') ?></dt><dd class="text-[17px] text-ink"><?= e($shop['legalName'] ?: $shop['name']) ?><?php if ($shop['legalId']) : ?><span class="block text-[15px] text-ink-2 ltr-nums"><?= e(($ar ? 'المعرّف الجبائي' : 'Matricule fiscal') . ' ' . $shop['legalId']) ?></span><?php endif ?></dd></div><?php endif ?>
<?php if ($shop['email']) : ?><div class="px-5 py-4"><dt class="text-[15px] text-muted"><?= e($ar ? 'الإيميل' : 'E-mail') ?></dt><dd><a href="mailto:<?= e($shop['email']) ?>" dir="ltr" class="text-[17px] text-ink underline-offset-4 hover:underline"><?= e($shop['email']) ?></a></dd></div><?php endif ?>
<?php if ($shop['address']) : ?><div class="px-5 py-4"><dt class="text-[15px] text-muted"><?= e($ar ? 'العنوان' : 'Adresse') ?></dt><dd class="text-[17px] text-ink"><?= e($shop['address']) ?></dd></div><?php endif ?>
<?php if ($facebook) : ?><div class="px-5 py-4"><dt class="text-[15px] text-muted">Facebook</dt><dd><a href="<?= e($facebook) ?>" target="_blank" rel="noopener" dir="ltr" class="break-all text-[17px] text-sea underline underline-offset-4"><?= e(preg_replace('#^https?://(www\.)?#', '', $facebook)) ?></a></dd></div><?php endif ?>
</dl>
<?php endif ?>
<a href="<?= e(url('/suivi')) ?>" class="inline-flex min-h-11 w-fit items-center text-[17px] text-sea underline underline-offset-4"><?= e(t('nav.track')) ?></a>
<?= PageUi::prose(t('footer.contact'), (string) ob_get_clean()) ?>
