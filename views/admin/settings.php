<?php
/** @var array $s */
$shop = $s['shop'];
$d = $s['delivery'];
?>
<h1>Paramètres</h1>
<form method="post">
  <?= Csrf::field() ?>
  <section class="cardx">
    <h2>La boutique</h2>
    <div class="row">
      <div><label for="name">Nom</label><input id="name" name="name" value="<?= e($shop['name']) ?>" maxlength="60"></div>
      <div><label for="phone">Téléphone (affiché)</label><input id="phone" name="phone" value="<?= e($shop['phone']) ?>" maxlength="20"></div>
      <div><label for="wa">WhatsApp (8 chiffres)</label><input id="wa" name="whatsapp" value="<?= e($s['contact']['whatsapp']) ?>" maxlength="20"></div>
      <div><label for="email">E-mail</label><input id="email" name="email" type="email" value="<?= e($shop['email']) ?>" maxlength="120"></div>
    </div>
    <div class="row">
      <div><label for="legalName">Vendeur (société ou nom)</label><input id="legalName" name="legalName" value="<?= e($shop['legalName']) ?>" maxlength="120"></div>
      <div><label for="legalId">Matricule fiscal / RNE</label><input id="legalId" name="legalId" value="<?= e($shop['legalId']) ?>" maxlength="60"></div>
      <div><label for="address">Adresse</label><input id="address" name="address" value="<?= e($shop['address']) ?>" maxlength="200"></div>
    </div>
    <div class="row">
      <div><label for="heroTitle">Titre de l'accueil (fr)</label><input id="heroTitle" name="heroTitle" value="<?= e($s['hero']['title']) ?>" maxlength="80"></div>
      <div><label for="heroTitleAr">Titre de l'accueil (ar)</label><input id="heroTitleAr" name="heroTitleAr" dir="rtl" value="<?= e($s['hero']['titleAr']) ?>" maxlength="80"></div>
    </div>
    <div class="row">
      <div><label for="tagline">Phrase sous le titre (fr)</label><input id="tagline" name="tagline" value="<?= e($shop['tagline']) ?>" maxlength="140"></div>
      <div><label for="taglineAr">Phrase sous le titre (ar)</label><input id="taglineAr" name="taglineAr" dir="rtl" value="<?= e($shop['taglineAr']) ?>" maxlength="140"></div>
    </div>
  </section>
  <section class="cardx">
    <h2>Livraison et retours</h2>
    <div class="row">
      <div><label for="fee">Frais de livraison (DT, partout)</label><input id="fee" name="fee" inputmode="decimal" value="<?= e(Money::input((int) $d['fee'])) ?>"></div>
      <div><label for="ft">Livraison offerte dès (DT, vide = jamais)</label><input id="ft" name="freeThreshold" inputmode="decimal" value="<?= e(Money::input($d['freeThreshold'])) ?>"></div>
      <div><label for="rd">Jours pour un retour (10 au moins, la loi)</label><input id="rd" name="returnDays" type="number" min="10" max="60" value="<?= (int) $s['returns']['days'] ?>"></div>
    </div>
    <label class="check"><input type="checkbox" name="openBeforePaying"<?= $d['openBeforePaying'] ? ' checked' : '' ?>> Le client peut ouvrir le colis avant de payer (selon le transporteur)</label>
    <div class="tablewrap"><table>
      <tr><th>Gouvernorat</th><th>Livré</th><th>Jours min.</th><th>Jours max.</th></tr>
      <?php foreach (array_keys(Gov::LIST) as $i => $g) : $z = $d['zones'][$g]; ?>
        <tr><td><?= e($g) ?></td>
          <td><input type="checkbox" name="zon[<?= $i ?>]"<?= $z['enabled'] ? ' checked' : '' ?> aria-label="Livré : <?= e($g) ?>"></td>
          <td><input type="number" min="0" max="30" name="zmin[<?= $i ?>]" value="<?= (int) $z['minDays'] ?>" style="max-width:90px" aria-label="Jours min. <?= e($g) ?>"></td>
          <td><input type="number" min="0" max="30" name="zmax[<?= $i ?>]" value="<?= (int) $z['maxDays'] ?>" style="max-width:90px" aria-label="Jours max. <?= e($g) ?>"></td></tr>
      <?php endforeach ?>
    </table></div>
  </section>
  <p class="muted small">Les autres réglages (bannières, pixels publicitaires, SMS, e-mails) restent ceux enregistrés : cette version ne les modifie pas.</p>
  <div class="actions"><button class="btn dark">Enregistrer</button></div>
</form>
