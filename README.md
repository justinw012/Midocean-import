# Midocean Import & Configurator

WordPress-plugin voor PromoFun. Synchroniseert Midocean-producten naar WooCommerce en vervangt op die productpagina's het bestelblok door een bedrukkingsconfigurator met staffelprijzen.

## Wat de plugin doet

- **Koppeling met Midocean**: haalt producten, prijzen, voorraad, printdata en de printprijslijst op via de Midocean-API (`x-Gateway-APIKey`).
- **Import per categorie**: alleen de categorieën die je aanvinkt. Nieuwe producten worden standaard als concept aangemaakt.
- **Bestaande producten**: worden herkend op artikelnummer (bijv. `AR1804-03`) en bijgewerkt. Naam en omschrijving blijven staan, tenzij je dat aanzet.
- **Achtergrond**: alles loopt in kleine stappen via Action Scheduler (zit in WooCommerce). Dagelijks een volledige sync om 03:00, elk uur de voorraad.
- **Configurator**: kleur, aantal, bedrukking per vlak (voorkant, achterkant, …), logo of eigen tekst, aantal kleuren, formaat en techniek. Live totaalprijs en staffeltabel.
- **Prijs aan de serverkant**: de winkelwagen rekent de prijs altijd opnieuw uit; de prijs uit de browser wordt niet gebruikt.
- **Logboek**: bevat alleen aantallen en artikelnummers. Geen API-sleutels, klantgegevens of bestandsinhoud.

## Installeren

1. Download de zip van de laatste release, of `Code → Download ZIP`.
2. WordPress: **Plugins → Plugin toevoegen → Plugin uploaden**, kies de zip en activeer.
3. **WooCommerce → Midocean**: plak je API-sleutel, kies de taal en sla op.
4. Klik **Verbinding testen**, daarna **Categorieën ophalen**. Ververs de pagina na een paar minuten.
5. Vink categorieën aan, controleer de opslagfactoren en sla op.
6. Klik **Nu synchroniseren**. De voortgang staat bovenaan de pagina en in het logboek.

Automatische updates vanuit GitHub kan met [Git Updater](https://git-updater.com): de plugin-header bevat `GitHub Plugin URI`.

## Prijsberekening

Per stuk = productprijs (Midocean-staffel × opslag product)
+ per bedrukte positie: drukprijs (staffel, en waar van toepassing kleuren en oppervlak) × opslag drukkosten
+ handling (Midocean-code per product) × opslag drukkosten.

Instelkosten × opslag instelkosten, per positie. In de winkelwagen worden ze verdeeld over de stukprijs, zodat ze meeschalen als de klant het aantal aanpast.

Prijstypes uit de printprijslijst: `NumberOfPositions`, `NumberOfColours`, `AreaRange`, `ColourAreaRange`, `Area`.

## Nog te controleren (aannames)

- **Opbouw van de JSON-feeds.** De veldnamen zijn afgeleid van de exports (CSV/XML/Excel) uit Postman. De plugin accepteert meerdere schrijfwijzen, maar is nog niet getest tegen de live API.
- **Instelkosten per kleur** bij zeefdruk, tampondruk en transfer. Staat aan; uit te zetten in de instellingen.
- **Grote feeds op gedeelde hosting.** De productfeed wordt in één keer naar schijf gedownload. Als de hosting de verbinding te vroeg afbreekt, staat dat in het logboek.
- **Advanced Dynamic Pricing**: kan prijzen in de winkelwagen aanpassen. Sluit Midocean-producten uit in de regels.
- **Kleurwissel in de themagalerij**: de configurator heeft een eigen voorbeeldweergave; de fotogalerij van het thema wisselt nog niet automatisch mee.

## Bestanden

| Bestand | Inhoud |
|---|---|
| `midocean-import.php` | Plugin-header en opstarten |
| `includes/class-mdo-api.php` | Downloaden van de feeds |
| `includes/class-mdo-json-stream.php` | Grote JSON-bestanden stuk voor stuk lezen |
| `includes/class-mdo-normalize.php` | Feeds omzetten naar vaste arrays |
| `includes/class-mdo-sync.php` | Achtergrondstappen, dagelijkse en uurlijkse sync |
| `includes/class-mdo-importer.php` | Producten, varianten, voorraad en afbeeldingen |
| `includes/class-mdo-printdata.php` | Drukposities groeperen tot vlakken, Nederlandse namen |
| `includes/class-mdo-pricing.php` | Prijsberekening (gelijk aan de JavaScript-versie) |
| `includes/class-mdo-frontend.php` | Configurator op de productpagina |
| `includes/class-mdo-cart.php` | Winkelwagen, bestelling en logo-upload |
| `includes/class-mdo-admin.php` | Instellingenpagina |
| `assets/configurator.js`, `.css` | De configurator |
