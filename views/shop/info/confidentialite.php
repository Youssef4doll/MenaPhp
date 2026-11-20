<?php
/**
 * What THIS version of the shop keeps: order details, and a session cookie
 * for the basket. It sets no advertising cookie and keeps no unfinished
 * forms (the Next.js shop does both, and says so on its own page).
 * @var array $s
 */
$name = (string) $s['shop']['name'];
$email = (string) $s['shop']['email'];
$phone = (string) $s['shop']['phone'];
?>
<div class="wrap narrow">
<?php if (lang() === 'ar') : ?>
  <h1 class="page-title">الخصوصية والكوكيز</h1>
  <div class="prose">
    <p>يعالج <?= e($name) ?> معطياتك الشخصية طبقًا للقانون الأساسي عدد 63 لسنة 2004 المتعلّق بحماية المعطيات الشخصية.</p>
    <h2>ما نحتفظ به</h2>
    <ul>
      <li>عند الطلب: الاسم، الهاتف، عنوان التوصيل، ومحتوى الطلبية. تُستعمل لتأكيد الطلبية وتوصيلها ومتابعتها.</li>
      <li>كوكي جلسة لحفظ السلة متاعك، وكوكي باش تلقى طلبياتك على الجهاز هذا.</li>
    </ul>
    <h2>الكوكيز الإشهارية</h2>
    <p>لا يستعمل الموقع حاليًا أيّ كوكيز إشهارية.</p>
    <h2>حقوقك</h2>
    <p>يمكنك طلب الاطّلاع على معطياتك أو تصحيحها أو حذفها بالاتصال بنا<?= $email ? ' على <bdi>' . e($email) . '</bdi>' : '' ?><?= $phone ? ' أو على الرقم <bdi dir="ltr">' . e($phone) . '</bdi>' : '' ?>.</p>
  </div>
<?php else : ?>
  <h1 class="page-title">Confidentialité et cookies</h1>
  <div class="prose">
    <p><?= e($name) ?> traite vos données conformément à la loi organique n° 2004-63 sur la protection des données à caractère personnel.</p>
    <h2>Ce que nous gardons</h2>
    <ul>
      <li>Pour une commande : nom, téléphone, adresse de livraison, contenu de la commande. Ils servent à confirmer, livrer et suivre la commande.</li>
      <li>Un cookie de session pour garder votre panier, et un cookie pour retrouver vos commandes sur cet appareil.</li>
    </ul>
    <h2>Cookies publicitaires</h2>
    <p>Le site n'utilise actuellement aucun cookie publicitaire.</p>
    <h2>Vos droits</h2>
    <p>Vous pouvez demander l'accès, la correction ou la suppression de vos données en nous contactant<?= $email ? ' à ' . e($email) : '' ?><?= $phone ? ' ou au ' . e($phone) : '' ?>.</p>
  </div>
<?php endif ?>
</div>
