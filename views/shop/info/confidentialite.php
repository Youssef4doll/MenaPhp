<?php
/** Privacy and cookies: what the shop keeps, the ad tools really set up. @var array $s */
$tr = $s['tracking'];
$pixels = array_values(array_filter([$tr['metaPixelId'] ? 'Meta (Facebook, Instagram)' : '', $tr['tiktokPixelId'] ? 'TikTok' : '', $tr['ga4Id'] ? 'Google Analytics' : '', ($tr['gtmId'] ?? '') ? 'Google Tag Manager' : '']));
$shop = $s['shop'];
ob_start();
if (lang() === 'ar') : ?>
<p>يعالج <?= e($shop['name']) ?> معطياتك الشخصية طبقًا للقانون الأساسي عدد 63 لسنة 2004 المتعلّق بحماية المعطيات الشخصية.</p>
<h2>ما نحتفظ به</h2>
<ul><li>عند الطلب: الاسم، الهاتف، عنوان التوصيل، ومحتوى الطلبية. تُستعمل لتأكيد الطلبية وتوصيلها ومتابعتها.</li><li>إذا بدأت طلبية ولم تكملها، نحتفظ بالاسم والهاتف والولاية التي كتبتها، لنتصل بك مرّة واحدة ونساعدك على إتمامها.</li><li>قياس داخلي لزيارات الموقع، دون كوكيز إشهارية: الصفحات التي تمّت زيارتها، الطلبيات التي بدأت، وعمليات البحث، مع معرّف مجهول.</li></ul>
<h2>الكوكيز الإشهارية</h2>
<?php if ($pixels) : ?><p>نستعمل أدوات القياس الخاصة بـ <bdi dir="ltr"><?= e(implode(', ', $pixels)) ?></bdi> لقياس إعلاناتنا. تنجم تمسح الكوكيز من إعدادات المتصفح متاعك.</p><?php else : ?><p>لا يستعمل الموقع حاليًا أيّ كوكيز إشهارية.</p><?php endif ?>
<h2>حقوقك</h2>
<p>يمكنك طلب الاطّلاع على معطياتك أو تصحيحها أو حذفها بالاتصال بنا<?php if ($shop['email']) : ?> على <bdi><?= e($shop['email']) ?></bdi><?php endif ?><?php if ($shop['phone']) : ?> أو على الرقم <bdi dir="ltr"><?= e($shop['phone']) ?></bdi><?php endif ?>.</p>
<?php $title = 'الخصوصية والكوكيز'; else : ?>
<p><?= e($shop['name']) ?> traite vos données conformément à la loi organique n° 2004-63 sur la protection des données à caractère personnel.</p>
<h2>Ce que nous gardons</h2>
<ul><li>Pour une commande : nom, téléphone, adresse de livraison, contenu de la commande. Ils servent à confirmer, livrer et suivre la commande.</li><li>Si vous commencez une commande sans la terminer, le nom, le téléphone et le gouvernorat saisis dans le formulaire sont gardés, pour que nous puissions vous recontacter une fois et vous aider à la terminer.</li><li>Une mesure d&#x27;audience interne au site, sans cookie publicitaire : pages vues, formulaires de commande commencés, recherches, avec un identifiant anonyme.</li></ul>
<h2>Cookies publicitaires</h2>
<?php if ($pixels) : ?><p>Nous utilisons les outils de mesure de <?= e(implode(', ', $pixels)) ?> pour mesurer nos publicités. Vous pouvez effacer ou bloquer ces cookies dans les réglages de votre navigateur.</p><?php else : ?><p>Le site n&#x27;utilise actuellement aucun cookie publicitaire.</p><?php endif ?>
<h2>Vos droits</h2>
<p>Vous pouvez demander l&#x27;accès, la correction ou la suppression de vos données en nous contactant<?= $shop['email'] ? ' à ' . e($shop['email']) : '' ?><?= $shop['phone'] ? ' ou au ' . e($shop['phone']) : '' ?>.</p>
<?php $title = 'Confidentialité et cookies'; endif;
echo PageUi::prose($title, (string) ob_get_clean());
