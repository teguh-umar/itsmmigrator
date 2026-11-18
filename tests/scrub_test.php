<?php

require __DIR__ . '/bootstrap.php';
require_once ITSM_ROOT . '/tools/lib_scrub.php';

// A made-up answer shaped like Freshservice's, full of things that must NOT survive.
$raw = [
    'ticket' => [
        'id' => 482913, 'subject' => 'Rahasia: laptop Pak Budi Santoso rusak', 'description' => '<div>Halo, hubungi budi.santoso@perusahaan-asli.co.id atau 0812-3456-7890</div>',
        'description_text' => 'Halo, hubungi budi.santoso@perusahaan-asli.co.id', 'status' => 2, 'priority' => 3, 'type' => 'Incident',
        'requester_id' => 770011, 'responder_id' => 551122, 'group_id' => 330044, 'category' => 'Perangkat Keras Internal', 'sub_category' => 'Laptop Direksi',
        'created_at' => '2024-03-01T01:02:03Z', 'updated_at' => '2024-03-02T04:05:06Z', 'due_by' => null, 'is_escalated' => false,
        'cc_emails' => ['atasan@perusahaan-asli.co.id'], 'custom_fields' => ['udf_sline_301' => 'FS#9988 internal-host-01', 'nomor_aset' => 'AST-778899'],
        'attachments' => [['id' => 88001, 'name' => 'foto-ruang-server-lantai-9.png', 'content_type' => 'image/png', 'size' => 2048, 'attachment_url' => 'https://s3.amazonaws.com/perusahaan-asli/secret/88001?X-Amz-Signature=abcdef']],
        'stats' => ['resolved_at' => '2024-03-02T03:00:00Z', 'closed_at' => null],
    ],
    'requesters' => [['id' => 770011, 'first_name' => 'Budi', 'last_name' => 'Santoso', 'primary_email' => 'budi.santoso@perusahaan-asli.co.id', 'secondary_emails' => ['bs@gmail.com'], 'job_title' => 'Kepala Divisi Rahasia', 'mobile_phone_number' => '081234567890', 'department_ids' => [4455], 'department_names' => ['Divisi Rahasia']]],
    'time_entries' => [['id' => 9, 'time_spent' => '01:30', 'note' => 'Ganti SSD di ruang CEO', 'agent_id' => 551122, 'executed_at' => '2024-03-02T01:00:00Z']],
    'groups' => [['id' => 330044, 'name' => 'Tim Rahasia Jaringan', 'agent_ids' => [551122, 551123]]],
];
$s = new FsScrubber();
$clean = $s->scrub($raw, 'ticket');
$json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

foreach (['Rahasia', 'Budi', 'Santoso', 'perusahaan-asli', 'budi.santoso', 'gmail', '0812', '081234567890', 'laptop', 'Laptop Direksi', 'Perangkat Keras Internal', 'internal-host', 'AST-778899', 'ruang-server', 'ruang CEO', 'amazonaws', 'X-Amz', 'secret', 'SSD', 'Divisi', 'Jaringan', 'Kepala', 'FS#9988', 'atasan'] as $needle) {
    check("scrub: '$needle' does not survive", stripos($json, $needle) === false);
}
check('scrub: no real id survives', !preg_match('/\b(482913|770011|551122|551123|330044|88001|4455)\b/', $json));
check('scrub: structure kept (same keys, same nesting)', array_keys($clean['ticket']) === array_keys($raw['ticket']) && array_keys($clean['ticket']['attachments'][0]) === array_keys($raw['ticket']['attachments'][0]) && array_keys($clean['ticket']['stats']) === array_keys($raw['ticket']['stats']));
check('scrub: types kept (ints, bools, nulls, lists)', is_int($clean['ticket']['status']) && is_bool($clean['ticket']['is_escalated']) && $clean['ticket']['due_by'] === null && is_array($clean['ticket']['cc_emails']) && is_array($clean['requesters'][0]['department_ids']));
check('scrub: enum values kept (status, priority, type, content_type)', $clean['ticket']['status'] === 2 && $clean['ticket']['priority'] === 3 && $clean['ticket']['type'] === 'Incident' && $clean['ticket']['attachments'][0]['content_type'] === 'image/png');
check('scrub: dates and durations kept', $clean['ticket']['created_at'] === '2024-03-01T01:02:03Z' && $clean['time_entries'][0]['time_spent'] === '01:30' && $clean['ticket']['stats']['resolved_at'] === '2024-03-02T03:00:00Z');
check('scrub: sizes and counts kept', $clean['ticket']['attachments'][0]['size'] === 2048);
check('scrub: the same real id becomes the same new id everywhere', $clean['ticket']['requester_id'] === $clean['requesters'][0]['id'] && $clean['ticket']['responder_id'] === $clean['time_entries'][0]['agent_id'] && $clean['ticket']['responder_id'] === $clean['groups'][0]['agent_ids'][0] && $clean['ticket']['group_id'] === $clean['groups'][0]['id']);
check('scrub: different real ids stay different', $clean['groups'][0]['agent_ids'][0] !== $clean['groups'][0]['agent_ids'][1] && $clean['ticket']['requester_id'] !== $clean['ticket']['responder_id']);
check('scrub: e-mails become example.test addresses', str_ends_with($clean['requesters'][0]['primary_email'], '@example.test') && str_ends_with($clean['requesters'][0]['secondary_emails'][0], '@example.test'));
check('scrub: the same e-mail gets the same placeholder', (new FsScrubber())->scrub(['a' => 'x@y.zz', 'b' => 'x@y.zz'], 'user') === ['a' => 'user1@example.test', 'b' => 'user1@example.test']);
check('scrub: HTML bodies stay HTML-shaped, plain text stays plain', str_starts_with($clean['ticket']['description'], '<div>') && !str_contains($clean['ticket']['description_text'], '<'));
check('scrub: link keeps its shape (a path and a signature marker), not its host', str_starts_with($clean['ticket']['attachments'][0]['attachment_url'], 'https://example.test/') && str_contains($clean['ticket']['attachments'][0]['attachment_url'], '?sig=x'));
check('scrub: custom field KEYS are kept, their values replaced', isset($clean['ticket']['custom_fields']['udf_sline_301']) && $clean['ticket']['custom_fields']['udf_sline_301'] !== 'FS#9988 internal-host-01');

// The category field of the ticket form: `name` identifies the FIELD there, the choices are company vocabulary.
$form = ['ticket_fields' => [['name' => 'category', 'label' => 'Kategori Internal', 'field_type' => 'nested_field', 'choices' => [['Perangkat Keras', ['Laptop Direksi', 'Server Rahasia']], 'Jaringan Internal']]]];
$cf = (new FsScrubber())->scrub($form, 'form');
$fj = json_encode($cf);
check('form scrub: the field name "category" is kept (the adapter looks for it)', $cf['ticket_fields'][0]['name'] === 'category');
check('form scrub: the choices tree keeps its shape', is_array($cf['ticket_fields'][0]['choices'][0]) && count($cf['ticket_fields'][0]['choices']) === 2 && count($cf['ticket_fields'][0]['choices'][0][1]) === 2 && is_string($cf['ticket_fields'][0]['choices'][1]));
check('form scrub: no company vocabulary survives', stripos($fj, 'Perangkat') === false && stripos($fj, 'Laptop') === false && stripos($fj, 'Rahasia') === false && stripos($fj, 'Jaringan') === false && stripos($fj, 'Internal') === false);

// An unknown string key is replaced, never kept (forgetting a field must leak nothing).
$u = (new FsScrubber())->scrub(['mystery_field' => 'Bocor Sekali', 'nested' => ['also_unknown' => 'Rahasia Dua']], 'ticket');
check('scrub: unknown string keys are replaced', stripos(json_encode($u), 'Bocor') === false && stripos(json_encode($u), 'Rahasia') === false);

// Real Freshservice shapes found on the first recording: ids under members/observers/escalate_to/member_of, categories as words.
$real = ['groups' => [['id' => 13000111222, 'name' => 'Tim Rahasia', 'escalate_to' => 13000417535, 'members' => [13000417535, 13000841028], 'observers' => [], 'leaders_pending_approval' => [13000841028]]],
    'agents' => [['id' => 13000417535, 'member_of' => [13000111222], 'observer_of' => [13000111222], 'email' => 'a@b.co', 'reporting_manager_id' => null]],
    'tickets' => [['category' => 'Perangkat Keras', 'sub_category' => 'Laptop', 'item_category' => null]],
    'ticket_fields' => [['name' => 'category', 'choices' => [['id' => 5, 'display_id' => 6, 'value' => 'Perangkat Keras', 'nested_options' => [['id' => 7, 'value' => 'Laptop', 'nested_options' => []]]]]]]];
$sx = new FsScrubber();
$g = $sx->scrub(['groups' => $real['groups'], 'agents' => $real['agents']], 'group');
$tj = json_encode($g);
check('real shape: no raw 1300... id survives anywhere', !preg_match('/13000\d{6}/', $tj));
check('real shape: members, escalate_to and the agent id agree', $g['groups'][0]['members'][0] === $g['agents'][0]['id'] && $g['groups'][0]['escalate_to'] === $g['agents'][0]['id'] && $g['groups'][0]['members'][1] === $g['groups'][0]['leaders_pending_approval'][0]);
check('real shape: member_of / observer_of point at the renumbered group', $g['agents'][0]['member_of'][0] === $g['groups'][0]['id'] && $g['agents'][0]['observer_of'] === $g['agents'][0]['member_of']);
$cat = $sx->scrub(['tickets' => $real['tickets']], 'ticket');
$frm = $sx->scrub(['ticket_fields' => $real['ticket_fields']], 'form');
check('real shape: a ticket category word and the form choice are the SAME placeholder', $cat['tickets'][0]['category'] === $frm['ticket_fields'][0]['choices'][0]['value'] && $cat['tickets'][0]['sub_category'] === $frm['ticket_fields'][0]['choices'][0]['nested_options'][0]['value']);
check('real shape: choice objects keep id, display_id, value, nested_options', array_keys($frm['ticket_fields'][0]['choices'][0]) === ['id', 'display_id', 'value', 'nested_options']);
check('real shape: a null item_category stays null', $cat['tickets'][0]['item_category'] === null);
// Status / priority wording on the form listing is kept (generic, and the mapping must be testable); codes under 100 stay.
$sf = (new FsScrubber())->scrub(['ticket_fields' => [['name' => 'status', 'choices' => [['id' => 2, 'value' => 'Open', 'requester_display_value' => 'Open', 'internal_id' => 13000000123], ['id' => 6, 'value' => 'Menunggu Vendor Rahasia']]], ['name' => 'group', 'choices' => [['id' => 13000555111, 'value' => 'Tim Rahasia']]]]], 'form');
check('vocab: status codes and wording are kept', $sf['ticket_fields'][0]['choices'][0]['id'] === 2 && $sf['ticket_fields'][0]['choices'][0]['value'] === 'Open' && $sf['ticket_fields'][0]['choices'][1]['id'] === 6);
check('vocab: an internal 11-digit id is still renumbered', $sf['ticket_fields'][0]['choices'][0]['internal_id'] < 100000);
check('vocab: other fields (group) keep nothing of the company\'s wording or ids', stripos(json_encode($sf['ticket_fields'][1]), 'Tim') === false && $sf['ticket_fields'][1]['choices'][0]['id'] < 100000);
check('ids: a 10+ digit number under ANY key is renumbered', (new FsScrubber())->scrub(['applied_business_hours' => 13000417535], 'ticket')['applied_business_hours'] < 100000);
check('ids: small ints under an id key stay (enumeration codes)', (new FsScrubber())->scrub(['id' => 4, 'priority_id' => 2], 'ticket') === ['id' => 4, 'priority_id' => 2]);
// The recorder itself: the key never leaves the process, no raw file is written.
$rec = (string) file_get_contents(ITSM_ROOT . '/tools/fs_record.php');
check('recorder: GET only (no other HTTP verb)', !preg_match('/CURLOPT_POST|->post\(|->put\(|->delete\(|CUSTOMREQUEST/i', $rec));
check('recorder: the key is never echoed or written', !preg_match('/(echo|print|fwrite|file_put_contents)[^;]*\$key/', $rec));
check('recorder: every file written goes through the scrubber', substr_count($rec, 'file_put_contents(') === 1 && str_contains($rec, '$clean = $scrub->scrub('));
check('recorder: tools/ is not shipped', str_contains((string) file_get_contents(ITSM_ROOT . '/.gitattributes'), 'tools/ export-ignore'));

done();
