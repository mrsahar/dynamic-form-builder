<?php
if (!defined('ABSPATH')) exit;

/**
 * Finnish (fi / fi_FI) string map when no compiled .mo is present.
 *
 * @return array<string,string>
 */
function dfb_get_fi_translations() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [
        // Frontend — hints & navigation
        'Short text answer' => 'Lyhyt tekstivastaus',
        'Email address (we will validate format)' => 'Sähköpostiosoite (tarkistamme muodon)',
        'Number only' => 'Vain numero',
        'Pick a date' => 'Valitse päivämäärä',
        'Longer, free-form answer' => 'Pidempi vapaamuotoinen vastaus',
        'Choose one option from the list' => 'Valitse yksi vaihtoehto listasta',
        'Choose one option' => 'Valitse yksi vaihtoehto',
        'You can select more than one option' => 'Voit valita useamman vaihtoehdon',
        'Choose Yes or No' => 'Valitse Kyllä tai Ei',
        'Help video' => 'Ohjevideo',
        'Watch help video' => 'Katso ohjevideo',
        'Invalid form ID.' => 'Virheellinen lomakkeen tunniste.',
        'This form is unavailable.' => 'Lomake ei ole käytettävissä.',
        'No questions configured for this form.' => 'Tälle lomakkeelle ei ole määritetty kysymyksiä.',
        'Select an option' => 'Valitse vaihtoehto',
        'Yes' => 'Kyllä',
        'No' => 'Ei',
        'Back' => 'Takaisin',
        'Next' => 'Seuraava',
        'Continue to Checkout' => 'Jatka kassalle',
        'Security check failed.' => 'Turvatarkistus epäonnistui.',
        'Please login to continue.' => 'Kirjaudu sisään jatkaaksesi.',
        'Please answer all required questions.' => 'Vastaa kaikkiin pakollisiin kysymyksiin.',
        'Could not save your response. Please try again.' => 'Vastausta ei voitu tallentaa. Yritä uudelleen.',
        'Redirecting to checkout...' => 'Ohjataan kassalle...',

        // Frontend — wp_localize_script + JS validation
        'Step %1$d of %2$d' => 'Vaihe %1$d / %2$d',
        'This field is required.' => 'Tämä kenttä on pakollinen.',
        'Please enter a valid email address.' => 'Anna kelvollinen sähköpostiosoite.',
        'Please choose an option.' => 'Valitse vaihtoehto.',
        'Please select at least one option.' => 'Valitse vähintään yksi vaihtoehto.',

        // PDF header
        'Generated on:' => 'Luotu:',

        // Admin — settings (abbreviated labels; long descriptions follow)
        'Settings saved.' => 'Asetukset tallennettu.',
        'Your generated document' => 'Tuotettu asiakirjasi',
        'Thank you for your order. Your generated document is attached.' => 'Kiitos tilauksestasi. Liitteenä on tuotettu asiakirja.',
        'Form Builder Settings' => 'Lomakkeen asetukset',
        'General' => 'Yleiset',
        'Signature' => 'Allekirjoitus',
        'Email' => 'Sähköposti',
        'Header Logo' => 'Ylätunnisteen logo',
        'Upload / Select Logo' => 'Lataa / valitse logo',
        'Remove' => 'Poista',
        'This logo will appear in the header of generated documents.' => 'Logo näkyy tuotettujen asiakirjojen ylätunnisteessa.',
        'Header Text' => 'Ylätunnisteen teksti',
        'Optional text displayed in the document header (for example company name, address, or contact details).' => 'Valinnainen teksti asiakirjan ylätunnisteessa (esim. yrityksen nimi, osoite tai yhteystiedot).',
        'Footer Text' => 'Alatunnisteen teksti',
        'Optional text displayed above the page number at the bottom of the document (for example legal notice or contact details).' => 'Valinnainen teksti sivunumeron yläpuolella asiakirjan alaosassa (esim. oikeudellinen huomautus tai yhteystiedot).',
        'Document Sections' => 'Asiakirjan osiot',
        'Add as many sections as you need. Each section will appear as its own block in the generated document.' => 'Lisää tarvittaessa useita osioita. Jokainen osio näkyy erillisenä lohkona tuotetussa asiakirjassa.',
        'Add Section' => 'Lisää osio',
        'PDF output' => 'PDF-tuloste',
        'Hide the questions in PDF' => 'Piilota kysymykset PDF:ssä',
        'When checked, question titles are hidden in the automatic answers list; answer values still appear. Your document template can still use placeholders such as {{question_1}}.' => 'Valittuna kysymysten otsikot piilotetaan automaattisesta vastauslistasta; vastausten arvot näkyvät silti. Lomakemalli voi edelleen käyttää paikkamerkkejä kuten {{question_1}}.',
        'Hide questions and answers in PDF (hide the automatic answers list)' => 'Piilota kysymykset ja vastaukset PDF:ssä (piilota automaattinen vastauslista)',
        'When checked, the automatic questions/answers list is not included in the PDF at all. Document templates can still use placeholders such as {{question_1}}.' => 'Valittuna automaattista kysymys/vastaus -listaa ei lisätä PDF:ään lainkaan. Lomakemalli voi edelleen käyttää paikkamerkkejä kuten {{question_1}}.',
        'Signature Block' => 'Allekirjoituslohko',
        'Configure the signature block that appears near the bottom of generated PDFs, above the footer.' => 'Määritä allekirjoituslohko, joka näkyy PDF:n alaosassa, alatunnisteen yläpuolella.',
        'Signature Title' => 'Allekirjoituksen otsikko',
        'Main heading shown above the signature row.' => 'Pääotsikko allekirjoitusrivin yläpuolella.',
        'Signature Description' => 'Allekirjoituksen kuvaus',
        'Optional description text shown under the signature title.' => 'Valinnainen kuvausteksti allekirjoituksen otsikon alle.',
        'Number of signatures' => 'Allekirjoitusten määrä',
        'Select how many signature columns should appear in the PDF. Signatures are laid out 2 per row (e.g. 6 signatures become 3 rows).' => 'Valitse kuinka monta allekirjoitussaraketta PDF:ssä näytetään. Allekirjoitukset asetetaan kaksi per rivi (esim. 6 allekirjoitusta = 3 riviä).',
        'Signature %d' => 'Allekirjoitus %d',
        'Label (e.g. Applicant Signature)' => 'Selite (esim. allekirjoittajan nimi)',
        'Text below signature line' => 'Teksti allekirjoitusviivan alla',
        'Customize the subject and body of the email sent to customers with their generated document attached.' => 'Mukauta sähköpostin aihe ja sisältö, joka lähetetään asiakkaille liitteenä tuotetulla asiakirjalla.',
        'Email Subject' => 'Sähköpostin aihe',
        'Subject line for the email that includes the generated document.' => 'Sähköpostin aihe rivi, joka sisältää tuotetun asiakirjan.',
        'Email Body' => 'Sähköpostin teksti',
        'Main message of the email. Basic HTML is allowed.' => 'Sähköpostin pääviesti. Perus-HTML on sallittu.',
        'Save Changes' => 'Tallenna muutokset',
        'Select Header Logo' => 'Valitse ylätunnisteen logo',
        'Use this logo' => 'Käytä tätä logoa',
        'Section' => 'Osio',
        'Section Title' => 'Osion otsikko',
        'Section Content' => 'Osion sisältö',

        // WooCommerce checkout guard
        'Please <a class="dfb-checkout-guard" href="%s">fill out the required form</a> before proceeding to checkout.' => 'Ole hyvä ja <a class="dfb-checkout-guard" href="%s">täytä vaadittu lomake</a> ennen siirtymistä kassalle.',

        // Admin — documents
        'Response and PDF file deleted.' => 'Vastaus ja PDF-tiedosto poistettu.',
        'Could not delete that document.' => 'Asiakirjaa ei voitu poistaa.',
        'Generated Documents' => 'Tuotetut asiakirjat',
        'If a row shows "PDF emailed" but the customer did not receive mail, your server often accepts wp_mail without delivering. Install WP Mail SMTP (or similar) and test. Status reflects WordPress mail success, not inbox delivery.' => 'Jos rivillä lukee "PDF lähetetty sähköpostilla", mutta asiakas ei saanut viestiä, palvelin voi hyväksyä wp_mailin ilman toimitusta. Asenna WP Mail SMTP (tms.) ja testaa. Tila kuvaa WordPressin postin onnistumista, ei perillemenoa.',
        'No form responses found yet.' => 'Lomakevastauksia ei ole vielä.',
        'Actions' => 'Toiminnot',
        'PDF emailed (wp_mail OK)' => 'PDF lähetetty sähköpostilla (wp_mail OK)',
        'Delete this response and its PDF file permanently?' => 'Poistetaanko tämä vastaus ja sen PDF-tiedosto pysyvästi?',
        'Unauthorized access.' => 'Ei käyttöoikeutta.',
        'Invalid request.' => 'Virheellinen pyyntö.',
        'PDF generator is not available.' => 'PDF-generaattori ei ole käytettävissä.',
        'Could not open PDF file.' => 'PDF-tiedostoa ei voitu avata.',

        // PDF / order notes
        'DFB: PDF emailed to %s.' => 'DFB: PDF lähetetty osoitteeseen %s.',
        'DFB: PDF email was not sent. On many hosts you must configure SMTP (e.g. WP Mail SMTP). Check wp_mail / PHPMailer logs.' => 'DFB: PDF-sähköpostia ei lähetetty. Monella palvelimella SMTP on määritettävä (esim. WP Mail SMTP). Tarkista wp_mail / PHPMailer -lokit.',

        // Admin — add form tips
        'Example: "This agreement is made on {{current_date}} between {{question_1}} and {{question_2}}..."' => 'Esimerkki: ”Tämä sopimus on tehty {{current_date}} välillä {{question_1}} ja {{question_2}}...”',
        'Tip: Type your document here using placeholders only. Do not copy the gray help text above into the editor — that text would appear in the PDF.' => 'Vinkki: kirjoita asiakirja tähän käyttämällä vain paikkamerkkejä. Älä kopioi yllä olevaa harmaata ohjetekstiä editoriin — se näkyisi PDF:ssä.',
    ];

    return $cache;
}

/**
 * @return bool
 */
function dfb_is_finnish_locale() {
    if (function_exists('pll_current_language')) {
        $slug = pll_current_language('slug');
        if (is_string($slug) && strtolower($slug) === 'fi') {
            return true;
        }
        $loc = pll_current_language('locale');
        if (is_string($loc) && $loc !== '') {
            $loc = strtolower($loc);
            if ($loc === 'fi' || $loc === 'fi_fi' || strpos($loc, 'fi_') === 0) {
                return true;
            }
        }
    }

    $wpml = apply_filters('wpml_current_language', null);
    if (is_string($wpml) && $wpml !== '') {
        $wpml = strtolower($wpml);
        if ($wpml === 'fi' || strpos($wpml, 'fi_') === 0) {
            return true;
        }
    }

    $candidates = [];
    if (function_exists('get_locale')) {
        $candidates[] = get_locale();
    }
    if (function_exists('determine_locale')) {
        $candidates[] = determine_locale();
    }
    foreach ($candidates as $l) {
        if (!is_string($l) || $l === '') {
            continue;
        }
        if ($l === 'fi' || $l === 'fi_FI' || strpos($l, 'fi_') === 0) {
            return true;
        }
    }

    return false;
}

/**
 * Apply bundled Finnish translations when gettext has no .mo entry.
 *
 * @param string $translation
 * @param string $text
 * @param string $domain
 * @return string
 */
function dfb_gettext_fi($translation, $text, $domain) {
    if ($domain !== 'dynamic-form-builder' || !is_string($text) || $text === '') {
        return $translation;
    }
    if (!dfb_is_finnish_locale()) {
        return $translation;
    }
    if (is_string($translation) && $translation !== $text) {
        return $translation;
    }
    $map = dfb_get_fi_translations();
    return isset($map[$text]) ? $map[$text] : $translation;
}

add_filter('gettext', 'dfb_gettext_fi', 20, 3);
