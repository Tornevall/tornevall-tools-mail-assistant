<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MailSupportAssistant\Mail\MimeDecoder;

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message . ' Missing text: ' . $needle . '.');
    }
}

function assertNotContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new RuntimeException($message . ' Unexpected text: ' . $needle . '.');
    }
}

$wrappedBody = <<<TEXT
Spam detection software, running on the system "tsrv04.tornevall.net",
has identified this incoming email as possible spam.

Content analysis details:   (13.2 points, 5.0 required)

pts rule name              description
---- ---------------------- --------------------------------------------------
 3.5 RCVD_IN_MSPIKE_BL      RBL: Blacklisted in mailspike

ForwardedMessage.emlÄmne: Notice of Claimed Infringement - Case ID 5ecd5c4daeca9bd65d18Från: Vobile Compliance <p2p@copyright-notice.com>Datum: 2026-04-18 03:50Till: abuse@tornevall.netReply-To: p2p@copyright-notice.comMessage-ID: ae84c0fb-3e26-4e92-b935-23eb7f990fe4@copyright-notice.comAuto-Submitted: auto-generated
-----BEGIN PGP SIGNED MESSAGE-----
Hash: SHA1

Notice ID: 5ecd5c4daeca9bd65d18
Notice Date: 2026-04-18T01:50:27Z

Dear Sir or Madam:

We are requesting your immediate assistance in removing and disabling access to the infringing material from your network.

Should you have any questions, please contact me at the information below.
TEXT;

$summary = MimeDecoder::extractRequestSummaryText($wrappedBody, 900);

assertContainsText('Notice ID: 5ecd5c4daeca9bd65d18', $summary, 'Malformed wrapper summaries should preserve the real original notice body.');
assertContainsText('We are requesting your immediate assistance in removing and disabling access to the infringing material from your network.', $summary, 'The extracted summary should include the actual request content.');
assertNotContainsText('Content analysis details', $summary, 'SpamAssassin analysis boilerplate should be removed from the extracted summary.');
assertNotContainsText('RCVD_IN_MSPIKE_BL', $summary, 'SpamAssassin rule rows should not remain in the extracted summary.');
assertNotContainsText('Ämne: Notice of Claimed Infringement', $summary, 'Embedded forwarded header dumps should be stripped from the extracted summary.');

fwrite(STDOUT, "malformed-message-summary-regression: ok\n");

