<?php
/**
 * Load a fictional sales book into SuiteCRM 8.10.
 *
 * Accounts, contacts, prices, volumes, and the sales staff marked fictional
 * are made up. They illustrate the public ARG business: Bradford, PA refinery,
 * light sweet paraffinic crude from PA/OH/NY/WV, ARGuard lubricants, Kendex
 * base oils / extracts / resins, Kensol solvents, Kenwax waxes, fuels, and
 * toll blending. Leadership names and the Bradford address/phone are public.
 * Every mailbox is @arg.example or a .example domain and is not a real inbox.
 *
 * Run by Seed-ARG-Data.ps1. Safe to run again; existing names are skipped.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '1');

function seed_env_file(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        putenv($parts[0] . '=' . $parts[1]);
    }
}

seed_env_file('/opt/arg-seed/seed.env');

$root = getenv('SUITECRM_ROOT') ?: '/var/www/suitecrm';
$legacy = $root . '/public/legacy';
if (!is_file($legacy . '/include/entryPoint.php')) {
    fwrite(STDERR, "SuiteCRM legacy entry point not found at $legacy\n");
    exit(1);
}

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

if (!defined('sugarEntry')) {
    define('sugarEntry', true);
}
chdir($legacy);
require_once $legacy . '/include/entryPoint.php';
require_once 'modules/Users/User.php';

$demoPassword = getenv('DEMO_USER_PASSWORD') ?: 'ArgDemo-ChangeMe-810';
$adminUser = getenv('ADMIN_USER') ?: 'admin';

$db = DBManagerFactory::getInstance();
$timedate = TimeDate::getInstance();

function say(string $message): void
{
    echo $message, PHP_EOL;
}

function existing_id(string $table, string $column, string $value): ?string
{
    $db = DBManagerFactory::getInstance();
    $sql = 'SELECT id FROM ' . $table . ' WHERE ' . $column . ' = ' . $db->quoted($value) . ' AND deleted = 0';
    $id = $db->getOne($sql);
    return $id ? (string) $id : null;
}

function on_date(int $offsetDays): string
{
    return date('Y-m-d', strtotime($offsetDays . ' days'));
}

function on_time(int $offsetDays, string $time = '09:00:00'): string
{
    return date('Y-m-d H:i:s', strtotime($offsetDays . ' days ' . $time));
}

function save_bean(string $module, array $fields)
{
    $email = $fields['email1'] ?? null;
    unset($fields['email1']);
    $bean = BeanFactory::newBean($module);
    foreach ($fields as $key => $value) {
        $bean->$key = $value;
    }
    if ($email) {
        $bean->email1 = $email;
    }
    $bean->save(false);
    if ($email) {
        $db = DBManagerFactory::getInstance();
        $have = $db->getOne(
            'SELECT e.id FROM email_addresses e '
            . 'INNER JOIN email_addr_bean_rel r ON r.email_address_id = e.id AND r.deleted = 0 '
            . 'WHERE r.bean_id = ' . $db->quoted($bean->id) . ' AND e.deleted = 0'
        );
        if (!$have && !empty($bean->emailAddress)) {
            $bean->emailAddress->addAddress($email, true);
            $bean->emailAddress->save($bean->id, $bean->module_dir);
        }
    }
    return $bean;
}

function set_user_prefs(string $userId): void
{
    try {
        $user = BeanFactory::getBean('Users', $userId);
        if (empty($user->id)) {
            return;
        }
        $user->setPreference('timezone', 'America/New_York');
        $user->setPreference('ut', '0');
        $user->setPreference('datef', 'm/d/Y');
        $user->setPreference('timef', 'h:ia');
        $user->savePreferencesToDB();
    } catch (Throwable $e) {
        say('  warn: timezone preference skipped (' . $e->getMessage() . ')');
    }
}

function split_row(string $line): array
{
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        return [];
    }
    return explode('|', $line);
}

$adminId = existing_id('users', 'user_name', $adminUser);
if (!$adminId) {
    fwrite(STDERR, "Admin user '$adminUser' was not found. Install SuiteCRM first.\n");
    exit(1);
}
$GLOBALS['current_user'] = BeanFactory::getBean('Users', $adminId);

say('Updating the administrator profile');
$admin = BeanFactory::getBean('Users', $adminId);
$admin->first_name = 'CRM';
$admin->last_name = 'Administrator';
$admin->title = 'SuiteCRM Administrator';
$admin->department = 'Information Technology';
$admin->phone_work = '(814) 368-1200';
$admin->address_street = '77 North Kendall Avenue';
$admin->address_city = 'Bradford';
$admin->address_state = 'PA';
$admin->address_postalcode = '16701';
$admin->address_country = 'USA';
$admin->description = 'Local administrator for the demo CRM. Change this password after signing in.';
$admin->employee_status = 'Active';
$admin->status = 'Active';
$admin->save(false);
set_user_prefs($adminId);

$currencyId = $db->getOne("SELECT id FROM currencies WHERE deleted = 0 AND iso4217 = 'USD' LIMIT 1");
if (!$currencyId) {
    $currencyId = '-99';
}

say('Creating employee users');
// username|first|last|title|department|reports_to|fictional 1/0
$userRows = <<<'ROWS'
rkrogmeier|Ryan|Krogmeier|President and Chief Operating Officer|Executive||0
bzolkos|Brian|Zolkos|Chief Financial Officer|Finance|rkrogmeier|0
jbrewer|Jeff|Brewer|Vice President, Operations|Operations|rkrogmeier|0
mkozminski|Michael|Kozminski|Vice President, Research and Development|Research and Development|rkrogmeier|0
pgriffin|Pete|Griffin|Vice President, Sales and Marketing|Sales|rkrogmeier|0
jsimpson|Joel|Simpson|Vice President, Supply Chain|Supply Chain|rkrogmeier|0
rsmith|Richard|Smith|Vice President and General Counsel|Legal|rkrogmeier|0
hboser|Heidi|Boser|Director, Human Resources|Human Resources|rkrogmeier|0
rbest|Ryan|Best|Director, Crude Supply and Logistics|Supply Chain|jsimpson|0
dwhitaker|Dana|Whitaker|Account Manager, Lubricants|Sales|pgriffin|1
mhale|Marcus|Hale|Account Manager, Base Oils and Extracts|Sales|pgriffin|1
evoss|Elena|Voss|Account Manager, Specialties, Solvents, and Waxes|Sales|pgriffin|1
cnguyen|Chris|Nguyen|Inside Sales, Fuels|Sales|pgriffin|1
sbennett|Sara|Bennett|Customer Service Representative|Customer Service|pgriffin|1
lokonkwo|Luis|Okonkwo|Logistics Coordinator|Supply Chain|jsimpson|1
ROWS;

$userIds = [];
$ext = 210;
foreach (explode("\n", trim($userRows)) as $line) {
    $c = split_row($line);
    if (!$c) {
        continue;
    }
    [$username, $first, $last, $title, $dept, $manager, $fictional] = $c;
    $id = existing_id('users', 'user_name', $username);
    if ($id) {
        say("  skip user $username");
        $userIds[$username] = $id;
        set_user_prefs($id);
        continue;
    }
    $fictional = $fictional === '1';
    $fields = [
        'user_name' => $username,
        'first_name' => $first,
        'last_name' => $last,
        'title' => $title,
        'department' => $dept,
        'status' => 'Active',
        'employee_status' => 'Active',
        'is_admin' => 0,
        'phone_work' => $fictional ? '(814) 555-0' . $ext : '(814) 368-1200',
        'address_street' => '77 North Kendall Avenue',
        'address_city' => 'Bradford',
        'address_state' => 'PA',
        'address_postalcode' => '16701',
        'address_country' => 'USA',
        'email1' => $username . '@arg.example',
        'description' => $fictional
            ? 'Fictional demo employee created so the CRM has a sales, service, and logistics bench. Not a real ARG person. Mailbox is not real.'
            : 'Public officer listed on amref.com, added as a demo CRM user. The @arg.example mailbox is not a real address.',
    ];
    $bean = save_bean('Users', $fields);
    $hash = User::getPasswordHash($demoPassword);
    $db->query(
        'UPDATE users SET user_hash = ' . $db->quoted($hash)
        . ", system_generated_password = '0', pwd_last_changed = " . $db->quoted($timedate->nowDb())
        . ' WHERE id = ' . $db->quoted($bean->id)
    );
    $userIds[$username] = $bean->id;
    set_user_prefs($bean->id);
    say("  add  user $username");
    $ext++;
}

foreach (explode("\n", trim($userRows)) as $line) {
    $c = split_row($line);
    if (!$c || $c[5] === '' || empty($userIds[$c[0]]) || empty($userIds[$c[5]])) {
        continue;
    }
    $db->query(
        'UPDATE users SET reports_to_id = ' . $db->quoted($userIds[$c[5]])
        . ' WHERE id = ' . $db->quoted($userIds[$c[0]]) . ' AND deleted = 0'
    );
}

function uid(array $userIds, string $username): string
{
    if (empty($userIds[$username])) {
        throw new RuntimeException("Unknown demo user $username");
    }
    return $userIds[$username];
}

function campaign_for(string $text, array $campaignIds): string
{
    $text = strtolower($text);
    $key = 'hseries';
    if (strpos($text, 'kensol') !== false || strpos($text, 'cutback') !== false) {
        $key = 'kensol';
    } elseif (strpos($text, 'crop') !== false || strpos($text, 'agricult') !== false) {
        $key = 'agpre';
    } elseif (strpos($text, 'arguard') !== false || strpos($text, 'private') !== false || strpos($text, 'toll') !== false) {
        $key = 'arguard';
    }
    return $campaignIds[$key] ?? '';
}

say('Creating product categories and the public product slate');
$categoryRows = <<<'ROWS'
ARGuard Lubricants|Finished and toll-blended lubricants sold under the ARGuard brand.
Kendex Base Oils|Hydrotreated paraffinic base oils, including the H-series and ARGold bright stock.
Kendex Extracts and Resins|Extracts and heavy resin used by rubber, asphalt, and industrial blenders.
Kensol Solvents|Solvent naphthas, asphalt cutback solvents, and well treating solvent.
Kenwax Waxes|Slack and scale waxes for firelogs, candles, and industrial coatings.
Agricultural and Defoamer Oils|Crop-oil carriers and defoamer oils for paper, coatings, and water treatment.
Fuels|Gasoline, diesel, heating oil, naphtha, and packaged camping fuel from the Bradford refinery.
Specialty and Custom|Quench oils, shock fluids, and toll-blend processing.
ROWS;
$categoryIds = [];
foreach (explode("\n", trim($categoryRows)) as $line) {
    $c = split_row($line);
    if (!$c) {
        continue;
    }
    $id = existing_id('aos_product_categories', 'name', $c[0]);
    if ($id) {
        $categoryIds[$c[0]] = $id;
        say('  skip category ' . $c[0]);
        continue;
    }
    $bean = save_bean('AOS_Product_Categories', [
        'name' => $c[0],
        'description' => $c[1] . ' Illustrative demo category, not an official price book.',
        'is_parent' => 1,
        'assigned_user_id' => uid($userIds, 'pgriffin'),
    ]);
    $categoryIds[$c[0]] = $bean->id;
    say('  add  category ' . $c[0]);
}

// sku|name|category|price|unit|cost ratio is applied below|description
$productRows = <<<'ROWS'
AG-10W30|ARGuard Synthetic Blend SAE 10W-30|ARGuard Lubricants|6.40|gallon|Passenger-car motor oil for private-label and regional blender programs.
AG-15W40|ARGuard Heavy Duty SAE 15W-40|ARGuard Lubricants|6.15|gallon|Heavy-duty diesel engine oil aimed at fleet and mixed-equipment accounts.
AG-HYD46|ARGuard AW Hydraulic Oil 46|ARGuard Lubricants|5.35|gallon|Anti-wear hydraulic fluid for industrial maintenance.
AG-HYD68|ARGuard AW Hydraulic Oil 68|ARGuard Lubricants|5.45|gallon|Heavier AW hydraulic grade for slower plant systems.
AG-80W90|ARGuard Gear Oil 80W-90|ARGuard Lubricants|5.90|gallon|GL-5 style gear oil for fleet and industrial gear sets.
AG-RO220|ARGuard R&O Circulating Oil 220|ARGuard Lubricants|5.10|gallon|Rust and oxidation circulating oil.
AG-BAR|ARGuard Bar and Chain Oil|ARGuard Lubricants|4.25|gallon|Seasonal bar and chain oil, often packed for farm and forestry resellers.
KX-0070|Kendex 0070 Hydrotreated Base Oil|Kendex Base Oils|4.15|gallon|Light hydrotreated neutral. Public H-series grade announced for industrial blenders.
KX-0100|Kendex 0100 Hydrotreated Base Oil|Kendex Base Oils|4.25|gallon|Light hydrotreated neutral used in process oils and hydraulic blends.
KX-200H|Kendex 200H Hydrotreated Base Oil|Kendex Base Oils|4.40|gallon|Hydrotreated grade with the color and oxidation story ARG has published for the H-series.
KX-250H|Kendex 250H Hydrotreated Base Oil|Kendex Base Oils|4.55|gallon|Mid-viscosity hydrotreated neutral for industrial lubricants.
KX-300H|Kendex 300H Hydrotreated Base Oil|Kendex Base Oils|4.70|gallon|Workhorse hydrotreated neutral for process oil and gear-oil blenders.
KX-325H|Kendex 325H Hydrotreated Base Oil|Kendex Base Oils|4.85|gallon|Heavier hydrotreated neutral in the published H-series.
KX-500H|Kendex 500H Hydrotreated Base Oil|Kendex Base Oils|5.10|gallon|Heavy hydrotreated neutral.
KX-600H|Kendex 600H Hydrotreated Base Oil|Kendex Base Oils|5.35|gallon|Heaviest published H-series neutral.
KX-BS|ARGold Hydrotreated Bright Stock|Kendex Base Oils|6.20|gallon|Hydrotreated bright stock sold as ARGold. Used where color and stability matter.
KX-0834|Kendex 0834 Heavy Resin|Kendex Extracts and Resins|3.90|gallon|Heavy resin used in asphalt, rubber, and industrial compounds.
KX-EXT|Kendex Extract Blend|Kendex Extracts and Resins|3.70|gallon|Extract blend for rubber process-oil and specialty applications.
KS-50|Kensol 50 Solvent Naphtha|Kensol Solvents|3.50|gallon|Distillate solvent naphtha for wax and oil removal and general solvent use.
KS-AC|Kensol Asphalt Cutback Solvent|Kensol Solvents|3.40|gallon|Seasonal cutback solvent for asphalt contractors.
KS-WT|Kensol Well Treating Solvent|Kensol Solvents|3.75|gallon|Well treating solvent for Appalachian oilfield chemical blenders.
KW-SLK|Kenwax Slack Wax|Kenwax Waxes|1.85|pound|Slack wax for firestarter logs, candles, and industrial coatings.
KW-SC|Kenwax Scale Wax|Kenwax Waxes|2.10|pound|Scale wax grade for packaging and candle accounts that want a drier wax.
AO-COC|Agricultural Crop Oil Carrier|Agricultural and Defoamer Oils|4.05|gallon|Carrier oil used in crop oil concentrates.
DF-100|Defoamer Oil 100|Agricultural and Defoamer Oils|4.30|gallon|Defoamer oil for pulp and paper, coatings, adhesives, and wastewater.
DF-150|Defoamer Oil 150|Agricultural and Defoamer Oils|4.45|gallon|Heavier defoamer oil where a higher viscosity is specified.
FU-REG|Conventional Gasoline|Fuels|2.55|gallon|Refinery gasoline grade. Demo rack-style number, not a posted ARG price.
FU-DSL|ULSD Diesel|Fuels|2.70|gallon|Ultra-low sulfur diesel. Demo number, not a posted ARG price.
FU-HO|Heating Oil|Fuels|2.60|gallon|Seasonal heating oil for Twin Tiers and McKean County resellers. Demo number.
FU-NAP|Naphtha|Fuels|2.90|gallon|Naphtha cut from the Bradford crude slate.
FU-CAMP|Camping Fuel|Fuels|6.80|gallon|Packaged camping fuel, a specialty of the Bradford product slate.
SP-QNCH|Quench Oil|Specialty and Custom|5.60|gallon|Quench oil for heat-treat and metalworking customers.
SP-SHK|Shock Fluid|Specialty and Custom|7.10|gallon|Specialty shock fluid.
SP-TOLL|Toll Blend Processing|Specialty and Custom|0.85|gallon|Processing fee per gallon for private-label toll blends. Service, not a catalog liquid.
ROWS;

$productInfo = [];
foreach (explode("\n", trim($productRows)) as $line) {
    $c = split_row($line);
    if (!$c) {
        continue;
    }
    [$sku, $name, $category, $price, $unit, $blurb] = $c;
    $price = (float) $price;
    $productInfo[$sku] = ['name' => $name, 'price' => $price, 'unit' => $unit];
    $id = existing_id('aos_products', 'part_number', $sku);
    if ($id) {
        $productInfo[$sku]['id'] = $id;
        say("  skip product $sku");
        continue;
    }
    if (empty($categoryIds[$category])) {
        throw new RuntimeException("Missing category $category");
    }
    $bean = save_bean('AOS_Products', [
        'name' => $name,
        'part_number' => $sku,
        'aos_product_category_id' => $categoryIds[$category],
        'currency_id' => $currencyId,
        'price' => $price,
        'cost' => round($price * 0.72, 2),
        'type' => $sku === 'SP-TOLL' ? 'Service' : 'Good',
        'description' => $blurb . ' Demo ' . $unit . ' price of $' . number_format($price, 2) . ' is illustrative only and is not an ARG price list.',
        'assigned_user_id' => uid($userIds, 'pgriffin'),
    ]);
    $productInfo[$sku]['id'] = $bean->id;
    say("  add  product $sku");
}

say('Creating accounts');
// key|name|type|industry|owner|street|city|state|zip|phone|domain|employees|revenue|description
$accountRows = <<<'ROWS'
ironclad|Ironclad Lubricants Co.|Customer|Manufacturing|dwhitaker|400 Peninsula Dr|Erie|PA|16501|(814) 555-0101|ironclad-lubricants.example|85|42000000|Private-label blender of engine oil and hydraulic fluid. Buys ARGuard finished lubricants and toll blending.
lakeshore|Lakeshore Blending LLC|Customer|Manufacturing|dwhitaker|18 Cuyahoga Industrial Pkwy|Cleveland|OH|44113|(216) 555-0144|lakeshore-blending.example|60|28000000|Industrial gear and circulating-oil blender serving Great Lakes maintenance distributors.
midwest|Midwest Process Oils|Customer|Chemicals|mhale|900 Front St|Toledo|OH|43604|(419) 555-0170|midwest-process-oils.example|40|31000000|Buys hydrotreated Kendex neutrals for process oils and industrial lubricant blends.
appelast|Appalachian Elastomers Inc.|Customer|Chemicals|mhale|250 Rubberway|Akron|OH|44301|(330) 555-0118|appalachian-elastomers.example|210|67000000|Rubber compounder. Regular buyer of Kendex extract blend.
ridge|Ridge Route Asphalt Co.|Customer|Construction|evoss|15 River Rd|Olean|NY|14760|(716) 555-0190|ridge-route-asphalt.example|120|54000000|Paving contractor. Seasonal Kensol asphalt cutback solvent program.
hearth|Hearth and Timber Firelogs|Customer|Manufacturing|evoss|8 Mill St|Kane|PA|16735|(814) 555-0166|hearth-timber.example|55|12000000|Firestarter-log plant. Slack wax is a core raw material.
seneca|Seneca Valley Adjuvants|Customer|Chemicals|evoss|40 Ag Park Dr|Geneva|NY|14456|(315) 555-0133|seneca-valley-adjuvants.example|35|18000000|Formulates crop oil concentrates and buys the agricultural carrier.
clarion|Clarion Paper Chemicals|Customer|Chemicals|mhale|600 Paper Mill Rd|Clarion|PA|16214|(814) 555-0122|clarion-paper-chemicals.example|70|26000000|Supplies defoamers to pulp and paper mills.
mckean|McKean County Fuels|Customer|Energy|cnguyen|120 Davis St|Bradford|PA|16701|(814) 555-0108|mckean-county-fuels.example|25|22000000|Local jobber lifting gasoline, diesel, and heating oil at Bradford.
twintiers|Twin Tiers Energy|Customer|Energy|cnguyen|77 Chemung St|Elmira|NY|14901|(607) 555-0155|twin-tiers-energy.example|40|35000000|Heating oil and diesel marketer served by truck and rail out of Bradford.
route219|Route 219 Travel Plaza Group|Customer|Retail|cnguyen|1 Plaza Dr|DuBois|PA|15801|(814) 555-0199|route219-plazas.example|180|48000000|Travel plazas along the Route 219 corridor. Conventional gasoline supply.
kinzua|Kinzua Heating Oil|Customer|Energy|cnguyen|55 Mechanic St|Bradford|PA|16701|(814) 555-0111|kinzua-heating.example|12|6000000|Home heating reseller in McKean County.
marcellus|Marcellus Well Chemicals|Customer|Energy|mhale|900 Reach Rd|Williamsport|PA|17701|(570) 555-0148|marcellus-well-chemicals.example|48|19000000|Oilfield chemical blender buying well treating solvent.
toolroom|Toolroom Fluids Inc.|Customer|Manufacturing|dwhitaker|300 Baldwin St|Meadville|PA|16335|(814) 555-0177|toolroom-fluids.example|30|9000000|Packs metalworking fluids and shock fluid for tooling distributors.
allegwax|Allegheny Packaging Wax|Customer|Manufacturing|evoss|12 Clark St|Warren|PA|16365|(814) 555-0184|allegheny-packaging-wax.example|22|7000000|Uses scale wax in packaging coatings.
hudson|Hudson Valley Lube Works|Customer|Manufacturing|dwhitaker|200 Broadway Industrial|Albany|NY|12202|(518) 555-0129|hudson-valley-lube.example|33|14000000|Passenger-car motor oil blender on an ARGuard private-label program.
ohiomat|Ohio River Construction Materials|Customer|Construction|evoss|50 Harmar St|Marietta|OH|45750|(740) 555-0160|ohio-river-materials.example|90|33000000|Asphalt materials yard. Cutback solvent and occasional heavy resin.
greatlakes|Great Lakes Industrial Fluids|Customer|Chemicals|mhale|1400 Michigan Ave|Detroit|MI|48216|(313) 555-0188|great-lakes-fluids.example|75|29000000|Hydraulic and gear-oil formulator qualifying the hydrotreated H-series.
chesapeake|Chesapeake Coatings|Customer|Chemicals|mhale|800 Boston St|Baltimore|MD|21224|(410) 555-0182|chesapeake-coatings.example|95|40000000|Industrial coatings plant using Kendex 0834 heavy resin.
adirondack|Adirondack Camp Fuel Co.|Customer|Retail|cnguyen|12 Broadway|Saranac Lake|NY|12983|(518) 555-0107|adirondack-camp-fuel.example|15|3500000|Packs and resells camping fuel for outfitters.
monvalley|Mon Valley Machine|Customer|Manufacturing|dwhitaker|44 McKeesport Rd|Charleroi|PA|15022|(724) 555-0131|mon-valley-machine.example|110|24000000|Machine shop and fleet shop. Hydraulic AW 46 and gear oil.
keystone|Keystone Private Label Oils|Prospect|Manufacturing|dwhitaker|500 Smallman St|Pittsburgh|PA|15222|(412) 555-0104|keystone-pl-oils.example|28|8000000|Evaluating a toll-blend and ARGuard private-label program for 2026.
pennyork|Penn-York Paving Supply|Prospect|Construction|evoss|90 Fairmount Ave|Jamestown|NY|14701|(716) 555-0138|pennyork-paving.example|40|11000000|Asked for a spring bid on Kensol asphalt cutback solvent.
candlewick|Candlewick and Co.|Prospect|Manufacturing|evoss|15 Prince St|Lancaster|PA|17603|(717) 555-0115|candlewick-co.example|18|4000000|Container-candle maker sampling Kenwax scale wax.
lakeerie|Lake Erie Ag Formulations|Prospect|Chemicals|evoss|230 Water St|Sandusky|OH|44870|(419) 555-0194|lake-erie-ag.example|20|5000000|Pre-season trial of the agricultural carrier oil.
mohawk|Mohawk Coatings|Prospect|Chemicals|mhale|75 Broad St|Utica|NY|13501|(315) 555-0172|mohawk-coatings.example|64|21000000|Paint plant asking whether Defoamer Oil 100 fits a new latex line.
tristate|Tri-State Formulators|Prospect|Chemicals|mhale|10 Market St|Wheeling|WV|26003|(304) 555-0126|tri-state-formulators.example|36|13000000|Sampling ARGold hydrotreated bright stock against an incumbent.
mtnquench|Mountain State Quench|Prospect|Manufacturing|dwhitaker|400 Kanawha Blvd|Charleston|WV|25301|(304) 555-0151|mountain-state-quench.example|27|7500000|Heat-treat shop comparing quench oil.
newrubber|New England Rubber Processors|Prospect|Manufacturing|mhale|88 Grafton St|Worcester|MA|01604|(508) 555-0191|ne-rubber.example|150|72000000|Qualification request for extract blend in a polymer line.
bradfordgather|Bradford Grade Gathering Co.|Partner|Energy|rbest|77 Main St|Bradford|PA|16701|(814) 555-0102|bradford-grade-gathering.example|45|0|Fictional crude gatherer. Stands in for local Pennsylvania paraffinic barrels.
alleghenysweet|Allegheny Sweet Crude Marketing|Partner|Energy|rbest|200 Seneca St|Oil City|PA|16301|(814) 555-0140|allegheny-sweet.example|20|0|Fictional crude marketing desk covering Pennsylvania, Ohio, New York, and West Virginia supply.
northerntier|Northern Tier Tank Car Logistics|Partner|Transportation|lokonkwo|500 Exchange St|Buffalo|NY|14204|(716) 555-0164|northern-tier-tank.example|80|0|Fictional rail partner moving bulk wax, base oil, and fuels.
calumet|Calumet Specialty Products|Competitor|Energy|pgriffin||Indianapolis|IN|||calumet.com|0|0|Public specialty-refining competitor. Reference record only. Not a customer and not a claim about their business with ARG.
hfsinclair|HF Sinclair Corporation|Competitor|Energy|pgriffin||Dallas|TX|||hfsinclair.com|0|0|Public refining competitor. Reference record only.
ergon|Ergon Inc.|Competitor|Chemicals|pgriffin||Jackson|MS|||ergon.com|0|0|Public specialty-products competitor. Reference record only.
ROWS;

$accountIds = [];
$accountMeta = [];
foreach (explode("\n", trim($accountRows)) as $line) {
    $c = split_row($line);
    if (!$c) {
        continue;
    }
    [$key, $name, $type, $industry, $owner, $street, $city, $state, $zip, $phone, $domain, $employees, $revenue, $blurb] = $c;
    $accountMeta[$key] = [
        'name' => $name,
        'street' => $street,
        'city' => $city,
        'state' => $state,
        'zip' => $zip,
        'domain' => $domain,
        'phone' => $phone,
        'owner' => $owner,
    ];
    $id = existing_id('accounts', 'name', $name);
    if ($id) {
        $accountIds[$key] = $id;
        say("  skip account $name");
        continue;
    }
    $prefix = 'FICTIONAL DEMO. Not a real ARG customer, volume, or price. ';
    if ($type === 'Competitor') {
        $prefix = 'PUBLIC COMPETITOR REFERENCE. Not a customer record. ';
    } elseif ($type === 'Partner') {
        $prefix = 'FICTIONAL SUPPLY-CHAIN PARTNER. Not a real vendor contract. ';
    }
    $bean = save_bean('Accounts', [
        'name' => $name,
        'account_type' => $type,
        'industry' => $industry,
        'phone_office' => $phone,
        'website' => $domain !== '' ? 'https://' . $domain : '',
        'employees' => $employees,
        'annual_revenue' => $revenue,
        'billing_address_street' => $street,
        'billing_address_city' => $city,
        'billing_address_state' => $state,
        'billing_address_postalcode' => $zip,
        'billing_address_country' => 'USA',
        'shipping_address_street' => $street,
        'shipping_address_city' => $city,
        'shipping_address_state' => $state,
        'shipping_address_postalcode' => $zip,
        'shipping_address_country' => 'USA',
        'assigned_user_id' => uid($userIds, $owner),
        'description' => $prefix . $blurb,
    ]);
    $accountIds[$key] = $bean->id;
    say("  add  account $name");
}

say('Creating contacts');
// account|first|last|title|department
$contactRows = <<<'ROWS'
ironclad|Helen|Piotrowski|Purchasing Manager|Procurement
ironclad|Neil|Ostrowski|Plant Superintendent|Operations
lakeshore|Carla|Benson|Buyer|Procurement
lakeshore|Owen|Drake|Quality Lead|Quality
midwest|Priya|Shah|Raw Materials Manager|Supply Chain
midwest|Greg|Halloran|Technical Director|R&D
appelast|Denise|Kline|Senior Buyer|Procurement
appelast|Victor|Russo|Compounding Manager|Manufacturing
ridge|Alan|McHale|Purchasing|Procurement
ridge|June|Keller|Operations Manager|Operations
hearth|Bob|Lenhart|Owner|Executive
hearth|Tina|Sayers|Production Planner|Operations
seneca|Marisol|Vega|Formulations Manager|R&D
seneca|Pete|Calloway|Buyer|Procurement
clarion|Howard|Briggs|Purchasing Manager|Procurement
clarion|Amy|Cho|Application Chemist|R&D
mckean|Frank|Dolan|General Manager|Executive
mckean|Kelly|Ward|Scheduler|Logistics
twintiers|Sandra|Perez|Supply Manager|Supply Chain
twintiers|Ian|Brooks|Terminal Supervisor|Operations
route219|Megan|Doyle|Fuel Buyer|Procurement
kinzua|Ruth|Ames|Owner|Executive
marcellus|Colin|Brewer|Procurement Lead|Procurement
marcellus|Nadia|Farrell|Chemist|R&D
toolroom|Jake|Horner|President|Executive
allegwax|Lois|Campbell|Buyer|Procurement
hudson|Erin|Walsh|Brand Manager|Marketing
hudson|Tom|Gaines|Blending Supervisor|Operations
ohiomat|Paul|Henning|Estimator|Sales
greatlakes|Sophie|Martel|Purchasing Manager|Procurement
greatlakes|Chris|Adler|Formulator|R&D
chesapeake|Robert|Nguyen|Raw Materials|Procurement
adirondack|Lily|Grant|Buyer|Procurement
monvalley|Dave|Polanski|Maintenance Superintendent|Maintenance
keystone|Andrea|Holt|General Manager|Executive
keystone|Sam|Iverson|Packaging Lead|Operations
pennyork|Bill|Yates|Purchasing|Procurement
candlewick|Nora|Kim|Product Developer|R&D
lakeerie|Grace|Potter|Owner|Executive
mohawk|Ellen|Voss|Process Engineer|Manufacturing
tristate|Mark|Sullivan|Technical Manager|R&D
mtnquench|Hank|Oliver|Plant Manager|Operations
newrubber|Claire|Bernstein|Purchasing Director|Procurement
bradfordgather|Ed|Crowley|Crude Scheduler|Supply
alleghenysweet|Jo|Miller|Marketer|Commercial
northerntier|Rick|Salinas|Rail Planner|Logistics
ROWS;

$contactIds = [];
$contactCount = [];
foreach (explode("\n", trim($contactRows)) as $line) {
    $c = split_row($line);
    if (!$c) {
        continue;
    }
    [$akey, $first, $last, $title, $dept] = $c;
    if (empty($accountIds[$akey])) {
        throw new RuntimeException("Contact refers to unknown account $akey");
    }
    $meta = $accountMeta[$akey];
    $n = ($contactCount[$akey] ?? 0) + 1;
    $contactCount[$akey] = $n;
    $ckey = $akey . '-' . $n;
    $email = strtolower($first . '.' . $last) . '@' . $meta['domain'];
    $existing = $db->getOne(
        'SELECT id FROM contacts WHERE first_name = ' . $db->quoted($first)
        . ' AND last_name = ' . $db->quoted($last)
        . ' AND deleted = 0'
    );
    if ($existing) {
        $contactIds[$ckey] = $existing;
        say("  skip contact $first $last");
        continue;
    }
    $bean = save_bean('Contacts', [
        'first_name' => $first,
        'last_name' => $last,
        'title' => $title,
        'department' => $dept,
        'account_id' => $accountIds[$akey],
        'account_name' => $meta['name'],
        'phone_work' => $meta['phone'],
        'email1' => $email,
        'primary_address_street' => $meta['street'],
        'primary_address_city' => $meta['city'],
        'primary_address_state' => $meta['state'],
        'primary_address_postalcode' => $meta['zip'],
        'primary_address_country' => 'USA',
        'lead_source' => 'Existing Customer',
        'assigned_user_id' => uid($userIds, $meta['owner']),
        'description' => 'Fictional demo contact at a fictional company. Not a real person.',
    ]);
    $contactIds[$ckey] = $bean->id;
    say("  add  contact $first $last");
}

say('Creating campaigns');
$campaignRows = <<<'ROWS'
hseries|Kendex hydrotreated H-series expansion|Complete|Email|-400|-20|25000|180000|Follow-up to the published hydrotreated base-oil expansion (0070, 0100, 200H, 250H, 300H, 325H, 500H, 600H).
arguard|ARGuard private-label outreach|Active|Telesales|-30|60|15000|420000|Call plan for blenders who could toll-blend or private-label ARGuard engine, hydraulic, and gear oils.
kensol|Spring Kensol cutback solvent|Planning|Email|-10|80|8000|260000|Pre-season offer to paving and construction-materials accounts.
agpre|Agricultural carrier pre-buy|Active|Web|-15|45|6000|150000|Pre-season sample and quote push for crop oil concentrate formulators.
ROWS;
$campaignIds = [];
foreach (explode("\n", trim($campaignRows)) as $line) {
    $c = split_row($line);
    if (!$c) {
        continue;
    }
    [$key, $name, $status, $type, $start, $end, $budget, $revenue, $objective] = $c;
    $id = existing_id('campaigns', 'name', $name);
    if ($id) {
        $campaignIds[$key] = $id;
        say("  skip campaign $name");
        continue;
    }
    $bean = save_bean('Campaigns', [
        'name' => $name,
        'status' => $status,
        'campaign_type' => $type,
        'start_date' => on_date((int) $start),
        'end_date' => on_date((int) $end),
        'budget' => $budget,
        'expected_cost' => $budget,
        'expected_revenue' => $revenue,
        'objective' => $objective,
        'content' => 'Demo campaign. Budgets and expected revenue are illustrative.',
        'assigned_user_id' => uid($userIds, 'pgriffin'),
    ]);
    $campaignIds[$key] = $bean->id;
    say("  add  campaign $name");
}

say('Creating opportunities');
$stageProb = [
    'Prospecting' => 10,
    'Qualification' => 20,
    'Needs Analysis' => 25,
    'Value Proposition' => 40,
    'Id. Decision Makers' => 50,
    'Perception Analysis' => 60,
    'Proposal/Price Quote' => 70,
    'Negotiation/Review' => 85,
    'Closed Won' => 100,
    'Closed Lost' => 0,
];
// account|name|stage|amount|type|closeDays|owner|source|next|description
$oppRows = <<<'ROWS'
midwest|Kendex 300H annual 2026|Closed Won|1450000|Existing Business|-35|mhale|Existing Customer|Ship against the blanket|Blanket for hydrotreated 300H into process-oil blends. Demo value, not a real contract.
midwest|Kendex 500H trial conversion|Proposal/Price Quote|280000|Existing Business|40|mhale|Existing Customer|Send COA pack and price hold|They will trial 500H in a heavier process oil if color holds.
greatlakes|H-series hydraulic base oil switch|Negotiation/Review|960000|New Business|25|mhale|Campaign|Review formulation data with their chemist|Switch from an incumbent neutral to Kendex 200H and 300H.
greatlakes|ARGold bright stock qualification|Needs Analysis|410000|New Business|70|mhale|Campaign|Ship two drum samples|Bright stock sample for an industrial gear package.
appelast|Extract blend annual renewal|Closed Won|890000|Existing Business|-80|mhale|Existing Customer|Watch monthly releases|Renewed extract-blend volume for the Akron compound line.
newrubber|Extract qualification, Worcester line|Value Proposition|720000|New Business|55|mhale|Trade Show|Book a plant trial window|Trade-show lead now in formulation review.
chesapeake|Kendex 0834 heavy resin 2026|Closed Won|540000|Existing Business|-15|mhale|Existing Customer|Confirm Q2 rail slots|Heavy resin into asphalt and industrial blends.
tristate|ARGold bright stock displacement|Perception Analysis|360000|New Business|45|mhale|Campaign|Answer oxidation questions|Head-to-head with their current bright stock.
clarion|Defoamer Oil 100 annual|Closed Won|310000|Existing Business|-50|mhale|Existing Customer|None|Paper-chemical defoamer oil on a release schedule.
mohawk|Defoamer oil latex-line trial|Qualification|125000|New Business|30|mhale|Web Site|Send sample and TDS|New latex coating needs a defoamer oil trial.
marcellus|Well treating solvent renewal|Negotiation/Review|470000|Existing Business|20|mhale|Existing Customer|Legal is reviewing the term sheet|Annual solvent nomination for well treating.
ironclad|ARGuard 15W-40 toll blend renewal|Negotiation/Review|860000|Existing Business|18|dwhitaker|Existing Customer|Confirm pail artwork|Private-label heavy-duty engine oil, blended and packed at Bradford.
ironclad|ARGuard AW 46 private label|Closed Won|390000|Existing Business|-120|dwhitaker|Existing Customer|None|Hydraulic 46 already shipping under their label.
hudson|ARGuard 10W-30 private label|Closed Won|510000|Existing Business|-60|dwhitaker|Existing Customer|Quarterly volume check|Passenger-car motor oil program.
hudson|Bar and chain seasonal pack|Proposal/Price Quote|75000|Existing Business|35|dwhitaker|Existing Customer|Send pack-size options|Spring quart program.
lakeshore|Gear oil 80W-90 tolling|Value Proposition|640000|New Business|50|dwhitaker|Cold Call|Tour the blending plant|They want ARG to toll-blend an 80W-90 under their brand.
keystone|First private-label program|Id. Decision Makers|480000|New Business|40|dwhitaker|Campaign|Meet their packaging lead in Pittsburgh|Toll blend plus ARGuard branding still in front of the owner.
toolroom|Shock fluid annual|Closed Won|160000|Existing Business|-25|dwhitaker|Existing Customer|None|Shock fluid packed for tooling distributors.
monvalley|Hydraulic and gear oil plant bid|Proposal/Price Quote|210000|New Business|28|dwhitaker|Existing Customer|Walk the lube room|Maintenance conversion to AW 46 and 80W-90.
mtnquench|Quench oil trial|Needs Analysis|95000|New Business|60|dwhitaker|Web Site|Ship a tote for the small furnace|Heat-treat trial, one furnace only.
ridge|Kensol cutback solvent summer|Closed Won|240000|Existing Business|-10|evoss|Existing Customer|Stage trucks before Memorial Day|Seasonal paving solvent.
ohiomat|Kensol cutback plus heavy resin|Proposal/Price Quote|305000|Existing Business|22|evoss|Existing Customer|Split the quote by mode|Truck solvent, rail resin.
pennyork|Spring cutback bid|Perception Analysis|180000|New Business|33|evoss|Campaign|Follow the bid tab|Jamestown paving bid. Price sensitive.
hearth|Kenwax slack wax annual|Closed Won|420000|Existing Business|-45|evoss|Existing Customer|Hold the tank for their campaign|Firelog wax through the heating season.
allegwax|Kenwax scale wax releases|Closed Won|150000|Existing Business|-20|evoss|Existing Customer|None|Monthly scale-wax releases.
candlewick|Scale wax candle trial|Qualification|60000|New Business|25|evoss|Web Site|Send a 5-pound lab sample|Container-candle trial. Color and odor matter.
seneca|Crop oil carrier pre-buy|Value Proposition|175000|Existing Business|15|evoss|Campaign|Lock the pre-buy price|Pre-season agricultural carrier.
lakeerie|Crop oil sample and first fill|Prospecting|80000|New Business|50|evoss|Campaign|Samples went out last week|New adjuvant formulator.
mckean|Gasoline and diesel supply|Closed Won|980000|Existing Business|-5|cnguyen|Existing Customer|Weekly rack plan|Local jobber lifting at Bradford. Demo revenue, not a posted rack.
twintiers|Heating oil winter|Closed Won|760000|Existing Business|-70|cnguyen|Existing Customer|Summer fill schedule next|Heating oil by rail and truck into Elmira.
kinzua|Heating oil keep-full|Closed Won|140000|Existing Business|-30|cnguyen|Existing Customer|None|Small keep-full reseller.
route219|Plaza gasoline nomination|Negotiation/Review|1100000|Existing Business|12|cnguyen|Existing Customer|Resolve the RVP timing|Summer gasoline for the travel plazas.
adirondack|Camping fuel pack 2026|Proposal/Price Quote|90000|Existing Business|20|cnguyen|Existing Customer|Confirm can labels|Packaged camping fuel for outfitters.
route219|Diesel at two new plazas|Closed Lost|260000|New Business|-14|cnguyen|Existing Customer|Lost on delivered price|They awarded diesel at two sites to another supplier. Kept for the forecast history.
ROWS;

foreach (explode("\n", trim($oppRows)) as $line) {
    $c = split_row($line);
    if (!$c) {
        continue;
    }
    [$akey, $name, $stage, $amount, $type, $closeDays, $owner, $source, $next, $blurb] = $c;
    if (!isset($stageProb[$stage])) {
        throw new RuntimeException("Bad stage $stage");
    }
    $fullName = $accountMeta[$akey]['name'] . ' - ' . $name;
    if (existing_id('opportunities', 'name', $fullName)) {
        say("  skip opportunity $fullName");
        continue;
    }
    $fields = [
        'name' => $fullName,
        'account_id' => $accountIds[$akey],
        'account_name' => $accountMeta[$akey]['name'],
        'sales_stage' => $stage,
        'probability' => $stageProb[$stage],
        'amount' => $amount,
        'currency_id' => $currencyId,
        'opportunity_type' => $type,
        'date_closed' => on_date((int) $closeDays),
        'lead_source' => $source,
        'next_step' => $next,
        'assigned_user_id' => uid($userIds, $owner),
        'description' => 'FICTIONAL DEMO OPPORTUNITY. ' . $blurb,
    ];
    if ($source === 'Campaign') {
        $fields['campaign_id'] = campaign_for($name . ' ' . $blurb, $campaignIds);
    }
    save_bean('Opportunities', $fields);
    say("  add  opportunity $fullName");
}

say('Creating open leads');
// first|last|title|company|status|source|city|state|phone|owner|campaign|description
$leadRows = <<<'ROWS'
Hannah|Brooks|Buyer|Birch and Copper Candle Co.|New|Web Site|Burlington|VT|(802) 555-0110|evoss|agpre|Website inquiry about Kenwax for a soy-wax blend. Fictional person.
Owen|Patel|Process Engineer|Northline Rubber|Assigned|Trade Show|Buffalo|NY|(716) 555-0142|mhale|hseries|Met at a rubber show. Wants an extract TDS. Fictional person.
Lila|Nguyen|Purchasing|Susquehanna Coatings|In Process|Web Site|Harrisburg|PA|(717) 555-0166|mhale|hseries|Asked for defoamer oil compatibility notes. Fictional person.
Carl|Yoder|Estimator|Youghiogheny Paving|Assigned|Campaign|Connellsville|PA|(724) 555-0180|evoss|kensol|Spring cutback-solvent inquiry from the Kensol campaign. Fictional person.
Rosa|Bennett|Owner|Chautauqua Ag Supply|In Process|Campaign|Dunkirk|NY|(716) 555-0109|evoss|agpre|Pre-buy question on the agricultural carrier. Fictional person.
Miles|Harper|Plant Manager|Lakeshore Metalworking|New|Cold Call|Mentor|OH|(440) 555-0134|dwhitaker||Quench oil cold call. Not the same company as Lakeshore Blending. Fictional person.
Ivy|Thompson|Buyer|Fort Bedford Fleet|Recycled|Cold Call|Bedford|PA|(814) 555-0195|cnguyen||Diesel fleet card request. Poor fit for refinery direct supply. Fictional person.
Jonah|Keller|Buyer|Greenridge Plastics|Assigned|Trade Show|Erie|PA|(814) 555-0128|mhale|hseries|Process-oil question for a polymer line. Fictional person.
Paige|Donnelly|Supply Manager|Ohio and Penn Independent|In Process|Existing Customer|Boardman|OH|(330) 555-0175|cnguyen||Gasoline supply conversation. Still unlicensed in our demo. Fictional person.
Elliot|Marsh|Chemist|Twin Lakes Paper|New|Web Site|Glens Falls|NY|(518) 555-0147|mhale||Defoamer oil sample request. Fictional person.
Wendy|Cho|Procurement|Keystone Drilling Fluids|Assigned|Web Site|Indiana|PA|(724) 555-0119|mhale||Well treating solvent for a small blender. Not Marcellus Well Chemicals. Fictional person.
Seth|Palmer|Owner|Harbor Wax Works|Dead|Web Site|Erie|PA|(814) 555-0161|evoss||Wanted a consumer candle kit, not bulk wax. Closed as a poor fit. Fictional person.
Gina|Falk|Maintenance Lead|Monongahela Machine|In Process|Cold Call|Charleroi|PA|(724) 555-0188|dwhitaker||Hydraulic oil inquiry. Not the same as Mon Valley Machine. Fictional person.
Hugh|Bradley|Buyer|Adirondack Outfitter Supply|New|Web Site|Saranac Lake|NY|(518) 555-0103|cnguyen||Camping fuel, case quantities. Separate from Adirondack Camp Fuel Co. Fictional person.
Tara|Singh|R&D Manager|Finger Lakes Adjuvants|Assigned|Campaign|Penn Yan|NY|(315) 555-0190|evoss|agpre|Second ag-carrier lead from the pre-buy page. Fictional person.
ROWS;

foreach (explode("\n", trim($leadRows)) as $line) {
    $c = split_row($line);
    if (!$c) {
        continue;
    }
    [$first, $last, $title, $company, $status, $source, $city, $state, $phone, $owner, $camp, $blurb] = $c;
    $existing = $db->getOne(
        'SELECT id FROM leads WHERE last_name = ' . $db->quoted($last)
        . ' AND account_name = ' . $db->quoted($company) . ' AND deleted = 0'
    );
    if ($existing) {
        say("  skip lead $first $last");
        continue;
    }
    $slug = trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $company)), '-');
    $email = strtolower($first . '.' . $last) . '@' . $slug . '.example';
    $fields = [
        'first_name' => $first,
        'last_name' => $last,
        'title' => $title,
        'account_name' => $company,
        'status' => $status,
        'lead_source' => $source !== '' ? $source : 'Other',
        'phone_work' => $phone,
        'email1' => $email,
        'primary_address_city' => $city,
        'primary_address_state' => $state,
        'primary_address_country' => 'USA',
        'assigned_user_id' => uid($userIds, $owner),
        'description' => 'FICTIONAL DEMO LEAD. ' . $blurb,
    ];
    if ($camp !== '' && !empty($campaignIds[$camp])) {
        $fields['campaign_id'] = $campaignIds[$camp];
    }
    save_bean('Leads', $fields);
    say("  add  lead $first $last / $company");
}

say('Creating service cases');
// account|name|status|priority|owner|description|resolution
$caseRows = <<<'ROWS'
ironclad|COA request for ARGuard 15W-40 lot B-441|Closed|P3|sbennett|Customer asked for the certificate of analysis on the last pail run.|COA emailed same day. Demo case.
midwest|Color question on Kendex 300H versus the spec sheet|Pending Input|P2|sbennett|Customer lab saw a color difference versus the prior hydrotreated lot. Waiting on their retest.|
twintiers|Rail car delayed, Bradford to Elmira heating oil|Assigned|P1|lokonkwo|Heating-oil car missed the interchange window. Customer is covering with truck gallons.|
ridge|Solvent odor on one Kensol compartment|Assigned|P1|sbennett|Driver reported an off odor in one compartment. Sample pulled and held.|
seneca|Cold-flow sample of crop oil carrier|New|P2|evoss|Formulator wants a retained sample before the pre-buy.|
mckean|Bill of lading gallons do not match the meter ticket|Pending Input|P1|sbennett|Short delivery question on diesel. Waiting on the rack meter print.|
clarion|Defoamer compatibility with a new coating package|Closed|P2|mkozminski|Application question routed to R&D.|Lab note says the 100 grade was acceptable in their jar test. Demo resolution.
marcellus|Flash-point certificate for well treating solvent|Assigned|P2|sbennett|They need the flash-point cert attached to the next invoice.|
lakeshore|Private-label pail artwork short on the side panel|New|P3|dwhitaker|Artwork is missing the caution line on one panel.|
appelast|Extract viscosity question on the last rail car|Closed|P2|mhale|Customer asked whether the viscosity drift was inside the agreed band.|Confirmed inside the published demo band and closed.
route219|Summer RVP change notice|Closed|P3|cnguyen|Seasonal gasoline notice to the travel-plaza buyer.|Notice sent. Demo case.
hearth|Slack wax melt point question|Assigned|P3|evoss|Firelog plant asked for the melt point on the last two lots.|
ROWS;

foreach (explode("\n", trim($caseRows)) as $line) {
    $c = split_row($line);
    if (!$c) {
        continue;
    }
    [$akey, $name, $status, $priority, $owner, $blurb, $resolution] = array_pad($c, 7, '');
    $full = $accountMeta[$akey]['name'] . ': ' . $name;
    if (existing_id('cases', 'name', $full)) {
        say("  skip case $full");
        continue;
    }
    $fields = [
        'name' => $full,
        'account_id' => $accountIds[$akey],
        'account_name' => $accountMeta[$akey]['name'],
        'status' => $status,
        'priority' => $priority,
        'type' => 'Product',
        'assigned_user_id' => uid($userIds, $owner),
        'description' => 'FICTIONAL DEMO CASE. ' . $blurb,
    ];
    if ($resolution !== '') {
        $fields['resolution'] = $resolution;
    }
    save_bean('Cases', $fields);
    say("  add  case $full");
}

say('Creating calls, meetings, tasks, and notes');
// kind|account|owner|day|status|name|detail
$activityRows = <<<'ROWS'
Meeting|ironclad|dwhitaker|-12|Held|Quarterly toll-blend review|Reviewed pail volumes and the 15W-40 artwork hold. Bradford conference room.
Call|greatlakes|mhale|-3|Held|H-series oxidation questions|Walked their formulator through the published hydrotreated color and sulfur story. Demo call.
Meeting|greatlakes|mhale|6|Planned|Sample review on site|Detroit plant. Bring 200H and 300H retain samples.
Call|marcellus|mhale|2|Planned|Term sheet follow-up|Legal redlines on the well treating solvent nomination.
Task|marcellus|sbennett|3|In Progress|Attach flash-point certificate|Pull the cert onto the next solvent invoice.
Meeting|ridge|evoss|-20|Held|Paving-season kickoff|Olean yard. Confirmed summer solvent trucks.
Call|pennyork|evoss|1|Planned|Bid follow-up|Ask where the Kensol number landed in their bid tab.
Task|candlewick|evoss|4|Not Started|Ship Kenwax lab sample|5-pound scale-wax sample to Lancaster.
Meeting|seneca|evoss|8|Planned|Pre-buy lock meeting|Geneva. Crop oil carrier price and delivery window.
Call|mckean|cnguyen|-1|Held|Weekly rack plan|Gasoline and diesel lifts for the Bradford jobber.
Task|twintiers|lokonkwo|0|In Progress|Recover the Elmira rail car|Car missed the interchange. Line up a truck bridge.
Meeting|keystone|dwhitaker|9|Planned|Pittsburgh toll-blend scoping|Meet the owner and the packaging lead. 77 North Kendall tour offered for the week after.
Call|hudson|dwhitaker|-6|Held|10W-30 volume check|Private-label quarts are on plan.
Task|lakeshore|dwhitaker|5|Not Started|Schedule blending-plant tour|Cleveland blender wants to see the pack line before tolling 80W-90.
Note|midwest|mhale|-2| |COA pack sent|Emailed the demo COA set for Kendex 300H and 500H. Not a real certificate.
Note|clarion|mkozminski|-18| |R&D jar-test note|Defoamer Oil 100 was acceptable in the customer jar test. Fictional lab note, not an ARG report.
Meeting|bradfordgather|rbest|-4|Held|Crude scheduling|Reviewed the coming month of local paraffinic barrels. Fictional gatherer.
Call|alleghenysweet|rbest|3|Planned|Ohio and West Virginia barrels|Ask what sweet paraffinic volume they can show for next month.
Meeting|northerntier|lokonkwo|7|Planned|Tank-car supply|Buffalo. Wax and base-oil car plan for the quarter.
Task|route219|cnguyen|2|Not Started|Send summer RVP notice|Plaza gasoline timing. Related to the closed service case.
Call|hearth|evoss|-8|Held|Slack wax tank level|Firelog plant will take the next truck early.
Note|pgriffin-house|pgriffin|0| |How this demo book was built|This CRM book is fictional. It mirrors public ARG facts only: Bradford refinery at 77 North Kendall Avenue, about 11,000 barrels per day of light sweet paraffinic crude from Pennsylvania, Ohio, New York, and West Virginia; products branded ARGuard, Kendex, Kensol, and Kenwax; ISO 9001 and Made in USA positioning; rail and truck, bulk and packaged. Prices, customers, and volumes are not ARG data.
ROWS;

foreach (explode("\n", trim($activityRows)) as $line) {
    $c = split_row($line);
    if (!$c) {
        continue;
    }
    [$kind, $akey, $owner, $day, $status, $name, $detail] = $c;
    $day = (int) $day;
    if ($akey === 'pgriffin-house') {
        $parentType = '';
        $parentId = '';
        $full = $name;
    } else {
        $parentType = 'Accounts';
        $parentId = $accountIds[$akey];
        $full = $accountMeta[$akey]['name'] . ': ' . $name;
    }
    $table = [
        'Call' => 'calls',
        'Meeting' => 'meetings',
        'Task' => 'tasks',
        'Note' => 'notes',
    ][$kind];
    if (existing_id($table, 'name', $full)) {
        say("  skip $kind $full");
        continue;
    }
    $common = [
        'name' => $full,
        'description' => $detail,
        'assigned_user_id' => uid($userIds, $owner),
    ];
    if ($parentId !== '') {
        $common['parent_type'] = $parentType;
        $common['parent_id'] = $parentId;
    }
    if ($kind === 'Call') {
        $common['direction'] = 'Outbound';
        $common['status'] = $status;
        $common['date_start'] = on_time($day, '10:00:00');
        $common['duration_hours'] = 0;
        $common['duration_minutes'] = 30;
        $common['reminder_time'] = -1;
        $common['email_reminder_time'] = -1;
        save_bean('Calls', $common);
    } elseif ($kind === 'Meeting') {
        $common['status'] = $status;
        $common['date_start'] = on_time($day, '09:30:00');
        $common['duration_hours'] = 1;
        $common['duration_minutes'] = 0;
        $common['location'] = $status === 'Held' && $day < 0
            ? '77 North Kendall Avenue, Bradford, PA'
            : ($accountMeta[$akey]['city'] . ', ' . $accountMeta[$akey]['state']);
        $common['reminder_time'] = -1;
        $common['email_reminder_time'] = -1;
        save_bean('Meetings', $common);
    } elseif ($kind === 'Task') {
        $common['status'] = $status;
        $common['priority'] = 'High';
        $common['date_start'] = on_time($day, '08:00:00');
        $common['date_due'] = on_time($day, '16:00:00');
        save_bean('Tasks', $common);
    } else {
        save_bean('Notes', $common);
    }
    say("  add  $kind $full");
}

say('Creating quotes');
$quoteRows = [
    ['midwest', 'midwest-1', 'mhale', 'Kendex 300H and 250H blanket', 'Closed Accepted', 20, [['KX-300H', 8000], ['KX-250H', 4000]]],
    ['ironclad', 'ironclad-1', 'dwhitaker', 'ARGuard 15W-40 toll blend', 'Negotiation', 30, [['AG-15W40', 6000], ['SP-TOLL', 6000]]],
    ['ridge', 'ridge-1', 'evoss', 'Kensol summer cutback', 'Delivered', 21, [['KS-AC', 12000]]],
    ['seneca', 'seneca-1', 'evoss', 'Crop oil carrier pre-buy', 'Confirmed', 18, [['AO-COC', 5000]]],
    ['hearth', 'hearth-1', 'evoss', 'Kenwax slack wax heating season', 'Draft', 14, [['KW-SLK', 20000]]],
    ['mckean', 'mckean-1', 'cnguyen', 'April gasoline and diesel lifts', 'Closed Accepted', -5, [['FU-REG', 20000], ['FU-DSL', 12000]]],
    ['clarion', 'clarion-1', 'mhale', 'Defoamer Oil 100 release', 'Negotiation', 12, [['DF-100', 4000]]],
    ['marcellus', 'marcellus-1', 'mhale', 'Well treating solvent nomination', 'Delivered', 10, [['KS-WT', 7000]]],
];

foreach ($quoteRows as $q) {
    [$akey, $ckey, $owner, $title, $stage, $exp, $lines] = $q;
    $name = $accountMeta[$akey]['name'] . ' - ' . $title;
    if (existing_id('aos_quotes', 'name', $name)) {
        say("  skip quote $name");
        continue;
    }
    if (empty($contactIds[$ckey])) {
        say("  warn: skipping quote $name, missing contact $ckey");
        continue;
    }
    try {
        $total = 0.0;
        $priced = [];
        foreach ($lines as [$sku, $qty]) {
            if (empty($productInfo[$sku]['id'])) {
                throw new RuntimeException("Missing product $sku");
            }
            $unit = (float) $productInfo[$sku]['price'];
            $lineTotal = round($unit * $qty, 2);
            $total += $lineTotal;
            $priced[] = [$sku, $qty, $unit, $lineTotal];
        }
        $quote = save_bean('AOS_Quotes', [
            'name' => $name,
            'stage' => $stage,
            'billing_account_id' => $accountIds[$akey],
            'billing_contact_id' => $contactIds[$ckey],
            'expiration' => on_date((int) $exp),
            'currency_id' => $currencyId,
            'assigned_user_id' => uid($userIds, $owner),
            'total_amt' => $total,
            'discount_amount' => 0,
            'subtotal_amount' => $total,
            'tax_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => $total,
            'description' => 'FICTIONAL DEMO QUOTE. Extended prices use the illustrative demo price list, not ARG prices.',
        ]);
        $group = save_bean('AOS_Line_Item_Groups', [
            'name' => 'Products',
            'parent_type' => 'AOS_Quotes',
            'parent_id' => $quote->id,
            'number' => 1,
            'currency_id' => $currencyId,
            'total_amt' => $total,
            'discount_amount' => 0,
            'subtotal_amount' => $total,
            'tax_amount' => 0,
            'total_amount' => $total,
            'assigned_user_id' => uid($userIds, $owner),
        ]);
        $n = 1;
        foreach ($priced as [$sku, $qty, $unit, $lineTotal]) {
            save_bean('AOS_Products_Quotes', [
                'name' => $productInfo[$sku]['name'],
                'parent_type' => 'AOS_Quotes',
                'parent_id' => $quote->id,
                'group_id' => $group->id,
                'product_id' => $productInfo[$sku]['id'],
                'product_qty' => $qty,
                'product_list_price' => $unit,
                'product_discount' => 0,
                'product_discount_amount' => 0,
                'discount' => 'Percentage',
                'product_unit_price' => $unit,
                'vat_amt' => 0,
                'vat' => '0.0',
                'product_total_price' => $lineTotal,
                'product_cost_price' => round($unit * 0.72, 2),
                'number' => $n,
                'currency_id' => $currencyId,
                'assigned_user_id' => uid($userIds, $owner),
                'description' => 'Demo line. Unit is ' . $productInfo[$sku]['unit'] . '.',
            ]);
            $n++;
        }
        say("  add  quote $name");
    } catch (Throwable $e) {
        say('  warn: quote failed (' . $e->getMessage() . ')');
    }
}

say('Creating supply agreements');
$contractRows = <<<'ROWS'
midwest|Kendex 300H blanket 2026|Signed|-35|330|1450000|mhale|Demo blanket matching the closed-won 300H opportunity. Not a real agreement.
hearth|Kenwax slack wax heating season|Signed|-45|180|420000|evoss|Demo wax supply agreement.
mckean|Rack supply, gasoline and diesel|Signed|-5|360|980000|cnguyen|Demo rack-supply agreement for the local jobber.
ironclad|ARGuard toll blend agreement|In Progress|-10|380|860000|dwhitaker|Demo toll-blend agreement still in negotiation, same story as the open 15W-40 opportunity.
ROWS;

foreach (explode("\n", trim($contractRows)) as $line) {
    $c = split_row($line);
    if (!$c) {
        continue;
    }
    [$akey, $name, $status, $start, $end, $value, $owner, $blurb] = $c;
    $full = $accountMeta[$akey]['name'] . ' - ' . $name;
    if (existing_id('aos_contracts', 'name', $full)) {
        say("  skip contract $full");
        continue;
    }
    try {
        save_bean('AOS_Contracts', [
            'name' => $full,
            'status' => $status,
            'start_date' => on_date((int) $start),
            'end_date' => on_date((int) $end),
            'contract_account_id' => $accountIds[$akey],
            'total_contract_value' => $value,
            'currency_id' => $currencyId,
            'reference_code' => 'DEMO-' . strtoupper($akey),
            'assigned_user_id' => uid($userIds, $owner),
            'description' => 'FICTIONAL DEMO CONTRACT. ' . $blurb,
        ]);
        say("  add  contract $full");
    } catch (Throwable $e) {
        say('  warn: contract failed (' . $e->getMessage() . ')');
    }
}

say('');
say('Seed finished. Record counts (includes anything already in the database):');
foreach (
    [
        'users' => 'Users',
        'accounts' => 'Accounts',
        'contacts' => 'Contacts',
        'leads' => 'Leads',
        'opportunities' => 'Opportunities',
        'aos_products' => 'Products',
        'aos_quotes' => 'Quotes',
        'aos_contracts' => 'Contracts',
        'cases' => 'Cases',
        'calls' => 'Calls',
        'meetings' => 'Meetings',
        'tasks' => 'Tasks',
        'notes' => 'Notes',
        'campaigns' => 'Campaigns',
    ] as $table => $label
) {
    $count = $db->getOne("SELECT COUNT(*) FROM $table WHERE deleted = 0");
    say(sprintf('  %-16s %s', $label, $count));
}
say('');
say('Demo employee password (all users except admin): ' . $demoPassword);
say('Admin login is unchanged. Read C:\\SuiteCRM\\credentials.txt for the admin password.');
