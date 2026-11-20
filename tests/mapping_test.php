<?php

require __DIR__ . '/bootstrap.php';
require_once ITSM_ROOT . '/src/Mapping.php';
require_once ITSM_ROOT . '/src/Engine.php';

// A stand-in for core's constants: the lookup's pure helper only needs these two.
class AuthLDAP
{
    public const IDENTIFIER_LOGIN = 'login';
    public const IDENTIFIER_EMAIL = 'email';
}
require_once ITSM_ROOT . '/src/Matching/LdapLookupInterface.php';
require_once ITSM_ROOT . '/src/Matching/GlpiLdapLookup.php';

use GlpiPlugin\Itsmmigrator\Engine;
use GlpiPlugin\Itsmmigrator\Mapping as M;
use GlpiPlugin\Itsmmigrator\Matching\GlpiLdapLookup;

// ---- status (spec §4 defaults)
check('Open with an assignee -> Assigned', M::status('Open', true) === ['value' => 2, 'known' => true]);
check('Open without an assignee -> New', M::status('Open', false) === ['value' => 1, 'known' => true]);
check('Pending -> Waiting (4)', M::status('Pending', true)['value'] === 4);
check('Resolved -> Solved (5), Closed -> Closed (6)', M::status('Resolved', true)['value'] === 5 && M::status('Closed', false)['value'] === 6);
check('SDP labels: On Hold -> Waiting, Cancelled -> Closed', M::status('On Hold', true)['value'] === 4 && M::status('Cancelled', true)['value'] === 6);
check('labels are case-insensitive', M::status('  CLOSED ', true)['value'] === 6);
$u = M::status('Weird', true);
check('unknown status -> default and known=false (a warning is logged)', $u['known'] === false && $u['value'] === 2);
check('the source map overrides the default', M::status('Open', true, ['open' => 4])['value'] === 4);
check('custom map keys are case-insensitive too', M::status('Open', true, ['OPEN' => 4])['value'] === 4);

// ---- priority and type
check('priority: Low 2, Medium 3, High 4, Urgent 5', array_map(fn ($l) => M::priority($l)['value'], ['Low', 'Medium', 'High', 'Urgent']) === [2, 3, 4, 5]);
check('unknown priority -> Medium, flagged', M::priority('???') === ['value' => 3, 'known' => false]);
check('type: Incident 1, Service Request 2', M::type('Incident')['value'] === 1 && M::type('Service Request')['value'] === 2);
check('unknown type -> Incident, flagged', M::type('Change') === ['value' => 1, 'known' => false]);
check('task state: todo/in_progress -> 1, done -> 2', M::taskState('todo') === 1 && M::taskState('in_progress') === 1 && M::taskState('done') === 2);

// ---- dates: never '' (strict TIMESTAMP), UTC -> local
check('null date stays null, never an empty string', M::localDate(null) === null);
$d = M::localDate(new DateTimeImmutable('2024-03-01T00:00:00Z'));
check('UTC -> the PHP timezone (Asia/Jakarta, +7)', $d === '2024-03-01 07:00:00', (string) $d);

// ---- text hash and title
check('hash ignores tags, case and whitespace', M::contentHash('<p>Hello   <b>World</b></p>') === M::contentHash("hello\nworld"));
check('hash decodes entities', M::contentHash('a &amp; b') === M::contentHash('a & b'));
check('different text, different hash', M::contentHash('one') !== M::contentHash('two'));
check('title: [FS#id] subject', M::ticketTitle('FS', '1001', ' Laptop ') === '[FS#1001] Laptop');
$long = M::ticketTitle('FS', '1001', str_repeat('é', 400));
check('title: never longer than the 255 characters of the GLPI column, the [prefix#id] key kept whole', mb_strlen($long) === 255 && str_starts_with($long, '[FS#1001] ') && M::ticketTitle('FS', '1001', str_repeat('a', 240)) === '[FS#1001] ' . str_repeat('a', 240));
check('title: empty subject gets a placeholder', M::ticketTitle('SDP', '7', '  ') === '[SDP#7] (no subject)');

// ---- --since
check('--since empty -> null', Engine::parseSince(null) === null && Engine::parseSince('') === null);
check('--since is read as UTC', Engine::parseSince('2024-03-01T07:00:00+07:00')?->format('c') === '2024-03-01T00:00:00+00:00');
try { Engine::parseSince('not a date'); check('--since garbage refused', false); } catch (InvalidArgumentException $e) { check('--since garbage refused', true); }

// ---- LDAP attempts: every e-mail, then the login
check('ldap attempts: e-mails first, then login', GlpiLdapLookup::attempts(['a@x.test', 'b@x.test'], 'bud') === [['email', 'a@x.test'], ['email', 'b@x.test'], ['login', 'bud']]);
check('ldap attempts: no login, no login attempt', GlpiLdapLookup::attempts(['a@x.test'], '') === [['email', 'a@x.test']]);

// ---- ticket writer: the properties the spec demands, checked in the source
$w = file_get_contents(ITSM_ROOT . '/src/Writer/TicketWriter.php');
check('tickets: actors added explicitly (Ticket::add drops them when the session entity differs)', str_contains($w, 'new \Ticket_User()') && str_contains($w, 'new \Group_Ticket()') && !str_contains($w, "'_users_id_requester'"));
check('tickets: status and dates written last through $DB->update', strpos($w, '$this->finalize(') > strpos($w, '$this->children(') && str_contains($w, "\$DB->update('glpi_tickets'"));
check('tickets: notifications are disabled on every write', substr_count($w, "'_disablenotif' => true") >= 6);
check('tickets: a ticket moved by a rule is put back in the target entity', str_contains($w, 'a business rule moved GLPI ticket'));

done();
