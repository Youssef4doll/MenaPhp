<?php
/** Terms of sale: a plain starting text filled from the settings. @var array $s @var bool $freeOffers */
$shop = $s['shop'];
$fee = (int) $s['delivery']['fee'];
$phone = $shop['phone'] ? Phone::format(Phone::normalize($shop['phone']) ?: $shop['phone']) : '';
$days = (int) $s['returns']['days'];
$wa = (bool) $s['contact']['whatsapp'];
ob_start();
if (lang() === 'ar') : ?>
<p>ينشر هذا الموقع <?= e($shop['legalName'] ?: $shop['name']) ?><?php if ($shop['legalId']) : ?>، المعرّف الجبائي <bdi><?= e($shop['legalId']) ?></bdi><?php endif ?><?php if ($shop['address']) : ?>، <?= e($shop['address']) ?><?php endif ?>.<?php if ($phone) : ?> الهاتف: <bdi dir="ltr"><?= e($phone) ?></bdi>.<?php endif ?><?php if ($shop['email']) : ?> البريد الإلكتروني: <bdi><?= e($shop['email']) ?></bdi>.<?php endif ?></p>
<h2>الطلبية</h2>
<p>تُسجَّل الطلبية عند تأكيدها على الموقع. نتصل بك بعد ذلك بالهاتف أو على واتساب لتأكيدها. يمكن إلغاء الطلبية التي لم يتمّ تأكيدها.</p>
<h2>الأسعار</h2>
<p>الأسعار معروضة بالدينار التونسي، وتشمل كل الأداءات. معلوم التوصيل <bdi><?= e(Money::format($fee, 'ar')) ?></bdi> مهما كانت الولاية<?= $fee > 0 && $freeOffers ? '، ومجاني في العروض اللي مكتوب عليها «التوصيل مجاني»' : '' ?>، ويظهر قبل تأكيد الطلبية. المبلغ الجملي المعروض عند الطلب هو المبلغ الذي تدفعه.</p>
<h2>الدفع</h2>
<p>يتمّ الدفع نقدًا عند الاستلام.</p>
<h2>التوصيل</h2>
<p>آجال التوصيل المذكورة هي الآجال المعتادة، تُحتسب من تاريخ تأكيد الطلبية، وليست تاريخًا مضمونًا. التفاصيل في صفحة التوصيل والإرجاع.</p>
<h2>حقّ الإرجاع</h2>
<p>طبقًا للقانون عدد 83 لسنة 2000 المتعلّق بالمبادلات والتجارة الإلكترونية، يمكنك العدول عن الشراء في أجل <?= $days ?> <?= $days > 10 ? 'يومًا' : 'أيام' ?> من تاريخ استلام المنتوج. يجب إرجاع المنتوج في حالته الأصلية. يتمّ استرجاع المبلغ بعد استلام المنتوج والتثبّت منه.</p>
<h2>الشكايات</h2>
<p>لأيّ سؤال أو شكاية، اتصل بنا<?= $wa ? ' على واتساب' : '' ?><?php if ($phone) : ?> على الرقم <bdi dir="ltr"><?= e($phone) ?></bdi><?php endif ?>.</p>
<?php $title = 'شروط البيع'; else : ?>
<p>Le site est édité par <?= e($shop['legalName'] ?: $shop['name']) ?><?= $shop['legalId'] ? ', matricule fiscal ' . e($shop['legalId']) : '' ?><?= $shop['address'] ? ', ' . e($shop['address']) : '' ?>.<?= $shop['phone'] ? ' Téléphone : ' . e($phone) . '.' : '' ?><?= $shop['email'] ? ' E-mail : ' . e($shop['email']) . '.' : '' ?></p>
<h2>Commande</h2>
<p>La commande est enregistrée quand vous la validez sur le site. Nous vous contactons ensuite par téléphone ou WhatsApp pour la confirmer. Une commande non confirmée peut être annulée.</p>
<h2>Prix</h2>
<p>Les prix sont indiqués en dinars tunisiens, toutes taxes comprises. La livraison coûte <?= e(Money::format($fee, 'fr')) ?>, quel que soit le gouvernorat<?= $fee > 0 && $freeOffers ? ', offerte sur les offres marquées « Livraison offerte »' : '' ?>, et s&#x27;affiche avant la validation. Le montant total affiché au moment de la commande est celui que vous payez.</p>
<h2>Paiement</h2>
<p>Le paiement se fait en espèces à la livraison.</p>
<h2>Livraison</h2>
<p>Les délais indiqués sont des délais habituels, à partir de la confirmation de la commande, et non une date garantie. Le détail est sur la page Livraison et retours.</p>
<h2>Droit de retour</h2>
<p>Conformément à la loi n° 2000-83 relative aux échanges et au commerce électroniques, vous pouvez vous rétracter dans un délai de <?= $days ?> jours à compter de la réception du produit. Le produit doit être retourné dans son état d&#x27;origine. Le remboursement intervient après réception et vérification du produit.</p>
<h2>Réclamations</h2>
<p>Pour toute question ou réclamation, contactez-nous<?= $wa ? ' sur WhatsApp' : '' ?><?= $shop['phone'] ? ' au ' . e($phone) : '' ?>.</p>
<?php $title = 'Conditions générales de vente'; endif;
echo PageUi::prose($title, (string) ob_get_clean());
