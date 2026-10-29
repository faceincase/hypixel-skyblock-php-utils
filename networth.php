<?php

declare(strict_types=1);

/**
 * Networth calculation for a Hypixel SkyBlock profile member.
 *
 * Networth = purse + bank + essence + the estimated value of every item and pet the player owns.
 * An item is valued as its own market price plus the market price of everything applied to it (reforge stone,
 * enchantments, gemstones, scrolls, potato books, dungeon stars, ...). Prices are the cheapest ones a buyer can pay
 * right now: Bazaar "instant buy" prices and the cheapest Auction House BIN.
 *
 * Needs: PHP 8.1+, allow_url_fopen, and `composer require aternos/nbt` (item data is gzipped NBT). See README.md.
 *
 *   require 'vendor/autoload.php';
 *   require 'networth.php';
 *   $result = calculatePlayerNetworth($member, $bankBalance);   // $member = profile['members'][uuid] from the API
 *   echo number_format($result['total']);
 *
 * Command line: `php networth.php refresh` rebuilds the Bazaar and Auction House caches (also started automatically,
 * in the background, when the auction snapshot is stale).
 */

// Where cached API data is written. Define NETWORTH_CACHE_DIR before including this file to move it.
if (!defined('NETWORTH_CACHE_DIR')) {
    define('NETWORTH_CACHE_DIR', __DIR__ . '/cache');
}
// Bazaar and Auction House prices are refreshed every 30 minutes; item names, gem slot costs and reforge stones daily.
const MARKET_CACHE_SECONDS = 1800;
const GAME_DATA_CACHE_SECONDS = 86400;
const NEU_RAW_URL = 'https://raw.githubusercontent.com/NotEnoughUpdates/NotEnoughUpdates-REPO/master/';
const PET_TIERS = ['COMMON' => 0, 'UNCOMMON' => 1, 'RARE' => 2, 'EPIC' => 3, 'LEGENDARY' => 4, 'MYTHIC' => 5];

// ---------------------------------------------------------------------------------------------------------------------
// Market data: Bazaar, Auction House, item list, reforge stones
// ---------------------------------------------------------------------------------------------------------------------

function fetchPublicJson(string $url, int $timeout = 15): ?array
{
    $context = stream_context_create(['http' => [
        'timeout' => $timeout,
        'ignore_errors' => true,
        'header' => "Accept: application/json\r\nUser-Agent: SkyBazaarPlayerLookup/1.0\r\n",
    ]]);
    $response = @file_get_contents($url, false, $context);
    $decoded = is_string($response) ? json_decode($response, true) : null;

    return is_array($decoded) && ($decoded['success'] ?? false) ? $decoded : null;
}

// Reads a market cache file if it is younger than $ttl seconds; $allowStale also returns an expired one.
function readMarketCache(string $path, bool $allowStale = false, int $ttl = MARKET_CACHE_SECONDS): ?array
{
    $entry = json_decode((string) @file_get_contents($path), true);
    if (!is_array($entry) || !is_array($entry['data'] ?? null)) {
        return null;
    }

    return $allowStale || time() - (int) ($entry['fetched_at'] ?? 0) < $ttl ? $entry['data'] : null;
}

// $info says what the file holds and $source where it came from; both are only there for whoever opens the file.
function writeMarketCache(string $path, array $data, string $info = '', string $source = ''): void
{
    if (!is_dir(NETWORTH_CACHE_DIR)) {
        @mkdir(NETWORTH_CACHE_DIR, 0755, true);
    }
    $entry = ['info' => $info, 'source' => $source, 'fetched_at' => time(), 'fetched_at_utc' => gmdate('c'), 'data' => $data];
    @file_put_contents($path, json_encode($entry, JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
}

// Bazaar prices (product id => ['buy' => instant buy price, 'sell' => instant sell price]), refreshed at most every 30
// minutes. Only these two numbers are cached, not the full order books.
function getBazaarPrices(): array
{
    static $memo = null;

    return $memo ??= (static function (): array {
        $path = NETWORTH_CACHE_DIR . '/bazaar.json';

        return readMarketCache($path) ?? buildBazaarPrices($path) ?? readMarketCache($path, true) ?? [];
    })();
}

function buildBazaarPrices(string $path): ?array
{
    $response = fetchPublicJson('https://api.hypixel.net/v2/skyblock/bazaar');
    if ($response === null || !is_array($response['products'] ?? null)) {
        return null;
    }
    $prices = [];
    foreach ($response['products'] as $productId => $product) {
        $prices[$productId] = [
            'buy' => (float) ($product['quick_status']['buyPrice'] ?? 0),
            'sell' => (float) ($product['quick_status']['sellPrice'] ?? 0),
        ];
    }
    writeMarketCache($path, $prices, 'Bazaar prices: product id => instant buy / instant sell price', 'https://api.hypixel.net/v2/skyblock/bazaar');

    return $prices;
}

// Cheapest active BIN auctions: ['exact' => name => price, 'alias' => name without its reforge => price]. A fresh
// snapshot is used as is. A stale one is still used while a detached process (`php networth.php refresh`) rebuilds it, because
// reading the whole auction house takes ~30 seconds and the built-in server handles one request at a time. Only when
// there is no snapshot at all does the page wait for it.
function getLowestBinPrices(): array
{
    static $memo = null;

    return $memo ??= (static function (): array {
        $path = NETWORTH_CACHE_DIR . '/auction-lowest-bin.json';
        $fresh = readMarketCache($path);
        if ($fresh !== null) {
            return $fresh;
        }
        $stale = readMarketCache($path, true);
        if ($stale !== null) {
            startBackgroundMarketRefresh();

            return $stale;
        }

        return buildLowestBinPrices($path) ?? ['exact' => [], 'alias' => []];
    })();
}

// Item name as listed minus dungeon stars and decorative symbols, so "✪✪✪✪✪" variants group with the plain item.
function normalizeAuctionName(string $name): string
{
    $name = preg_replace('/\s+[✪➊-➓⚚].*$/u', '', $name) ?? $name;

    return trim(preg_replace('/^[^\p{L}\p{N}\[]+/u', '', $name) ?? $name);
}

function buildLowestBinPrices(string $path): ?array
{
    @set_time_limit(180);
    $exact = [];
    $alias = [];
    $pets = [];
    $totalPages = 1;
    for ($page = 0; $page < $totalPages; $page++) {
        $response = fetchPublicJson('https://api.hypixel.net/v2/skyblock/auctions?page=' . $page);
        if ($response === null) {
            return null; // a partial snapshot would give wrong "cheapest" prices
        }
        $totalPages = (int) ($response['totalPages'] ?? 1);
        foreach ($response['auctions'] ?? [] as $auction) {
            if (empty($auction['bin']) || !empty($auction['claimed']) || !is_numeric($auction['starting_bid'] ?? null)) {
                continue;
            }
            $rawName = (string) ($auction['item_name'] ?? '');
            $bid = (int) $auction['starting_bid'];
            // Pets are listed as "[Lvl 100] Ender Dragon": keep the cheapest listing per rarity and level band.
            if (preg_match('/^\[Lvl (\d+)\] (.+)$/u', $rawName, $petMatch)) {
                $band = (int) $petMatch[1] >= 200 ? 'l200' : ((int) $petMatch[1] >= 100 ? 'l100' : ((int) $petMatch[1] <= 1 ? 'l1' : null));
                $petKey = (string) ($auction['tier'] ?? '') . '|' . trim($petMatch[2]);
                if ($band !== null && $bid < ($pets[$petKey][$band] ?? PHP_INT_MAX)) {
                    $pets[$petKey][$band] = $bid;
                }
                continue;
            }
            $name = normalizeAuctionName($rawName);
            if ($name === '') {
                continue;
            }
            if ($bid < ($exact[$name] ?? PHP_INT_MAX)) {
                $exact[$name] = $bid;
            }
            // Reforges are one word in front ("Fabled Hyperion"), so dropping the first word gives the plain name.
            $space = strpos($name, ' ');
            if ($space !== false) {
                $plain = substr($name, $space + 1);
                if ($bid < ($alias[$plain] ?? PHP_INT_MAX)) {
                    $alias[$plain] = $bid;
                }
            }
        }
    }
    $snapshot = ['exact' => $exact, 'alias' => $alias, 'pets' => $pets];
    writeMarketCache($path, $snapshot, 'Auction house lowest BIN prices: exact item name, name without reforge (alias) and pets by tier/name', 'https://api.hypixel.net/v2/skyblock/auctions (all pages)');

    return $snapshot;
}

// Rebuilds the market caches from a separate PHP process, at most once every five minutes.
function startBackgroundMarketRefresh(): void
{
    $lock = NETWORTH_CACHE_DIR . '/market-refresh.lock';
    if (is_file($lock) && time() - (int) @filemtime($lock) < 300) {
        return;
    }
    @touch($lock);

    $command = '"' . PHP_BINARY . '" "' . __FILE__ . '" refresh';
    if (PHP_OS_FAMILY === 'Windows') {
        @pclose(@popen('start /B "" ' . $command . ' > NUL 2>&1', 'r'));
    } else {
        @exec($command . ' > /dev/null 2>&1 &');
    }
}

// SkyBlock item id => ['name', 'slots' (gemstone slots with unlock costs), 'upgrades' (dungeon star costs per star)].
function getSkyblockItemInfo(): array
{
    static $memo = null;

    return $memo ??= (static function (): array {
        $path = NETWORTH_CACHE_DIR . '/skyblock-items.json';
        $cached = readMarketCache($path, false, GAME_DATA_CACHE_SECONDS);
        if ($cached !== null) {
            return $cached;
        }

        $response = fetchPublicJson('https://api.hypixel.net/v2/resources/skyblock/items', 30);
        if ($response === null || !is_array($response['items'] ?? null)) {
            return readMarketCache($path, true) ?? [];
        }
        $items = [];
        foreach ($response['items'] as $item) {
            if (is_string($item['id'] ?? null) && is_string($item['name'] ?? null)) {
                $items[$item['id']] = [
                    'name' => $item['name'],
                    'slots' => $item['gemstone_slots'] ?? [],
                    'upgrades' => $item['upgrade_costs'] ?? [],
                ];
            }
        }
        writeMarketCache($path, $items, 'SkyBlock item list: item id => name, gemstone slots and dungeon star costs', 'https://api.hypixel.net/v2/resources/skyblock/items');

        return $items;
    })();
}

// Lower-case reforge name => the reforge stone's item id (e.g. "fleet" => DIAMONITE). Community data from the
// NotEnoughUpdates repo; the Hypixel API does not say which stone gives which reforge.
function getReforgeStones(): array
{
    static $memo = null;

    return $memo ??= (static function (): array {
        $path = NETWORTH_CACHE_DIR . '/reforge-stones.json';
        $cached = readMarketCache($path, false, GAME_DATA_CACHE_SECONDS);
        if ($cached !== null) {
            return $cached;
        }

        $context = stream_context_create(['http' => ['timeout' => 15, 'header' => "User-Agent: SkyBazaarPlayerLookup/1.0\r\n"]]);
        $raw = @file_get_contents('https://raw.githubusercontent.com/NotEnoughUpdates/NotEnoughUpdates-REPO/master/constants/reforgestones.json', false, $context);
        $stones = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($stones)) {
            return readMarketCache($path, true) ?? [];
        }
        $byReforge = [];
        foreach ($stones as $stoneId => $stone) {
            if (is_array($stone) && is_string($stone['reforgeName'] ?? null)) {
                $byReforge[strtolower($stone['reforgeName'])] = (string) $stoneId;
            }
        }
        writeMarketCache($path, $byReforge, 'Reforge name => reforge stone item id (community data)', 'NotEnoughUpdates-REPO constants/reforgestones.json');

        return $byReforge;
    })();
}

// Cheapest BIN for an item name: the plain name if anyone lists it, otherwise the cheapest reforged copy.
function getAuctionPrice(string $name): ?float
{
    $prices = getLowestBinPrices();
    $price = $prices['exact'][$name] ?? $prices['alias'][$name] ?? null;

    return $price !== null ? (float) $price : null;
}

// Price of one item by SkyBlock id: the Bazaar's instant-buy price when it has one, otherwise the cheapest BIN listing
// under the item's display name. Null when neither market has it.
function getItemPrice(string $itemId): ?float
{
    $bazaar = getBazaarPrices()[$itemId] ?? null;
    if ($bazaar !== null && ($bazaar['buy'] > 0 || $bazaar['sell'] > 0)) {
        return $bazaar['buy'] > 0 ? $bazaar['buy'] : $bazaar['sell'];
    }

    $name = getSkyblockItemInfo()[$itemId]['name'] ?? null;

    return $name !== null ? getAuctionPrice($name) : null;
}

// Price of an enchantment book at a level. When that exact level is not sold, the level below is doubled (two books
// combine into the next level). Efficiency above V also needs one Silex per extra level.
function getEnchantmentPrice(string $enchantment, int $level): ?float
{
    $extra = 0.0;
    if ($enchantment === 'efficiency' && $level > 5) {
        $silex = getItemPrice('SIL_EX');
        if ($silex === null) {
            return null;
        }
        $extra = ($level - 5) * $silex;
        $level = 5;
    }

    // Scaling further than one level down inflates rare high-level books (Compact X from Compact I would be 512 books).
    for ($tier = $level; $tier >= max(1, $level - 1); $tier--) {
        $price = getItemPrice('ENCHANTMENT_' . strtoupper($enchantment) . '_' . $tier);
        if ($price !== null) {
            return $price * 2 ** ($level - $tier) + $extra;
        }
    }

    // Efficiency V is not sold on the Bazaar, so only the Silex counts.
    return $extra > 0 ? $extra : null;
}

// Plain PHP array from an NBT compound or list.
function nbtToArray(\Aternos\Nbt\Tag\Tag $tag): mixed
{
    if ($tag instanceof \Aternos\Nbt\Tag\CompoundTag || $tag instanceof \Aternos\Nbt\Tag\ListTag) {
        $out = [];
        foreach ($tag as $key => $value) {
            $out[$key] = nbtToArray($value);
        }

        return $out;
    }

    // Byte/int/long array tags (e.g. skull or enchant data) have no scalar value, and nothing priced uses them.
    return method_exists($tag, 'getValue') ? $tag->getValue() : null;
}

// Estimated value of an item: its own price (a Bazaar stack, or the cheapest BIN of the same item) plus the market
// price of everything applied to it. Returns the total and a breakdown (label => coins); applied parts the markets have
// no price for are listed in 'unpriced' and count as 0. Null when the item itself cannot be priced.
function estimateItemValue(?\Aternos\Nbt\Tag\CompoundTag $extraAttributes, int $count = 1): ?array
{
    $extra = $extraAttributes !== null ? nbtToArray($extraAttributes) : [];
    $itemId = is_string($extra['id'] ?? null) ? $extra['id'] : '';
    if ($itemId === '' || $itemId === 'PET') {
        return null;
    }

    $info = getSkyblockItemInfo();
    $idLabel = static fn(string $id): string => $info[$id]['name'] ?? $id;

    $parts = [];
    if ($itemId !== 'ENCHANTED_BOOK') {
        $base = getItemPrice($itemId);
        if ($base === null) {
            return null;
        }
        $parts[$idLabel($itemId) . ($count > 1 ? ' x' . $count : '')] = $base * max(1, $count);
    }

    $unpriced = [];
    $add = static function (string $label, ?float $price, float|int $quantity = 1) use (&$parts, &$unpriced): void {
        if ($price === null) {
            $unpriced[] = $label;
        } elseif ($quantity > 0) {
            $parts[$label] = ($parts[$label] ?? 0.0) + $price * $quantity;
        }
    };

    $reforgeStone = getReforgeStones()[strtolower((string) ($extra['modifier'] ?? ''))] ?? null;
    if ($reforgeStone !== null) {
        $add($idLabel($reforgeStone), getItemPrice($reforgeStone));
    }

    // Counters: how many of an applied item the player used on this one.
    $consumables = [
        'rarity_upgrades' => 'RECOMBOBULATOR_3000',
        'polarvoid' => 'POLARVOID_BOOK',
        'divan_powder_coating' => 'DIVAN_POWDER_COATING',
        'art_of_war_count' => 'THE_ART_OF_WAR',
        'wood_singularity_count' => 'WOOD_SINGULARITY',
        'farming_for_dummies_count' => 'FARMING_FOR_DUMMIES',
        'jalapeno_count' => 'JALAPENO_BOOK',
        'mana_disintegrator_count' => 'MANA_DISINTEGRATOR',
        'tuned_transmission' => 'TRANSMISSION_TUNER',
    ];
    foreach ($consumables as $field => $consumableId) {
        if (is_numeric($extra[$field] ?? null) && $extra[$field] > 0) {
            $add($idLabel($consumableId), getItemPrice($consumableId), (int) $extra[$field]);
        }
    }

    // The first ten potato books are Hot, the rest Fuming.
    $potatoes = (int) ($extra['hot_potato_count'] ?? 0);
    $add($idLabel('HOT_POTATO_BOOK'), getItemPrice('HOT_POTATO_BOOK'), min($potatoes, 10));
    $add($idLabel('FUMING_POTATO_BOOK'), getItemPrice('FUMING_POTATO_BOOK'), max(0, $potatoes - 10));

    // Fields that hold the id of one applied item.
    foreach (['power_ability_scroll', 'dye_item', 'drill_part_upgrade_module', 'drill_part_engine', 'drill_part_fuel_tank'] as $field) {
        if (is_string($extra[$field] ?? null) && $extra[$field] !== '') {
            $appliedId = strtoupper($extra[$field]);
            $add($idLabel($appliedId), getItemPrice($appliedId));
        }
    }
    foreach (is_array($extra['ability_scroll'] ?? null) ? $extra['ability_scroll'] : [] as $scrollId) {
        if (is_string($scrollId)) {
            $add($idLabel($scrollId), getItemPrice($scrollId));
        }
    }
    if (is_string($extra['talisman_enrichment'] ?? null) && $extra['talisman_enrichment'] !== '') {
        $enrichmentId = 'TALISMAN_ENRICHMENT_' . strtoupper($extra['talisman_enrichment']);
        $add($idLabel($enrichmentId), getItemPrice($enrichmentId));
    }

    $numerals = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'];
    foreach (is_array($extra['enchantments'] ?? null) ? $extra['enchantments'] : [] as $enchantment => $level) {
        if (is_numeric($level) && $level > 0) {
            $enchantName = ucwords(str_replace('_', ' ', preg_replace('/^ultimate_/', '', (string) $enchantment)));
            $add($enchantName . ' ' . ($numerals[(int) $level] ?? (int) $level), getEnchantmentPrice((string) $enchantment, (int) $level));
        }
    }

    // Dungeon stars: the essence and items spent on each star up to the item's level.
    $stars = (int) ($extra['upgrade_level'] ?? $extra['dungeon_item_level'] ?? 0);
    foreach (array_slice($info[$itemId]['upgrades'] ?? [], 0, max(0, $stars)) as $starCosts) {
        foreach ($starCosts as $cost) {
            if (($cost['type'] ?? '') === 'ESSENCE' && is_string($cost['essence_type'] ?? null)) {
                $essenceId = 'ESSENCE_' . $cost['essence_type'];
                $add(ucfirst(strtolower($cost['essence_type'])) . ' Essence (stars)', getItemPrice($essenceId), (int) ($cost['amount'] ?? 0));
            } elseif (($cost['type'] ?? '') === 'ITEM' && is_string($cost['item_id'] ?? null)) {
                $add($idLabel($cost['item_id']) . ' (stars)', getItemPrice($cost['item_id']), (int) ($cost['amount'] ?? 1));
            }
        }
    }

    // Gemstones sit in slots named TYPE_n; universal slots (e.g. MINING_0) name their gem in TYPE_n_gem.
    $gems = is_array($extra['gems'] ?? null) ? $extra['gems'] : [];
    foreach ($gems as $slot => $gem) {
        if (!preg_match('/^([A-Z]+)_\d+$/', (string) $slot, $slotMatch)) {
            continue;
        }
        $quality = is_array($gem) ? ($gem['quality'] ?? null) : $gem;
        $type = $gems[$slot . '_gem'] ?? $slotMatch[1];
        if (is_string($quality) && is_string($type)) {
            $gemId = strtoupper($quality . '_' . $type) . '_GEM';
            $add($idLabel($gemId), getItemPrice($gemId));
        }
    }

    // Slots that had to be unlocked cost coins and items; free slots are not listed in unlocked_slots.
    $slotsByType = [];
    foreach ($info[$itemId]['slots'] ?? [] as $slotDefinition) {
        $slotsByType[$slotDefinition['slot_type'] ?? ''][] = $slotDefinition;
    }
    foreach (is_array($gems['unlocked_slots'] ?? null) ? $gems['unlocked_slots'] : [] as $unlockedSlot) {
        if (!preg_match('/^([A-Z]+)_(\d+)$/', (string) $unlockedSlot, $slotMatch)) {
            continue;
        }
        foreach ($slotsByType[$slotMatch[1]][(int) $slotMatch[2]]['costs'] ?? [] as $cost) {
            if (($cost['type'] ?? '') === 'COINS') {
                $add('Gemstone slot unlock (coins)', 1.0, (float) ($cost['coins'] ?? 0));
            } elseif (($cost['type'] ?? '') === 'ITEM' && is_string($cost['item_id'] ?? null)) {
                $add($idLabel($cost['item_id']) . ' (slot unlock)', getItemPrice($cost['item_id']), (int) ($cost['amount'] ?? 1));
            }
        }
    }

    if ($parts === []) {
        return null;
    }

    return ['total' => (int) round(array_sum($parts)), 'parts' => $parts, 'unpriced' => $unpriced];
}

// 123,456,789 => 123M, 23,400 => 23K (no decimals)
function formatRoundedCoins(float $value): string
{
    foreach ([1e9 => 'B', 1e6 => 'M', 1e3 => 'K'] as $size => $suffix) {
        if ($value >= $size) {
            return number_format($value / $size, 0, '.', '') . $suffix;
        }
    }

    return number_format($value);
}

// ---------------------------------------------------------------------------------------------------------------------
// Pets (level tables come from the community NotEnoughUpdates repo; the Hypixel API has none)
// ---------------------------------------------------------------------------------------------------------------------

function fetchNeuFile(string $path, ?int &$status = null): ?string
{
    $context = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true, 'header' => "User-Agent: SkyBazaarPlayerLookup/1.0\r\n"]]);
    $body = @file_get_contents(NEU_RAW_URL . $path, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
            $status = (int) $match[1];
        }
    }

    return is_string($body) && $status === 200 ? $body : null;
}

function getPetConstants(): array
{
    static $memo = null;

    return $memo ??= (static function (): array {
        $path = NETWORTH_CACHE_DIR . '/neu-pets.json';
        $cached = readMarketCache($path, false, GAME_DATA_CACHE_SECONDS);
        if ($cached !== null) {
            return $cached;
        }

        $raw = fetchNeuFile('constants/pets.json');
        $data = $raw !== null ? json_decode($raw, true) : null;
        if (!is_array($data) || !is_array($data['pet_levels'] ?? null)) {
            return readMarketCache($path, true) ?? [];
        }
        $constants = array_intersect_key($data, array_flip(['pet_levels', 'pet_rarity_offset', 'custom_pet_leveling', 'id_to_display_name']));
        writeMarketCache($path, $constants, 'Pet leveling tables and display names (community data)', 'NotEnoughUpdates-REPO constants/pets.json');

        return $constants;
    })();
}

function getPetSteps(string $type, string $tier): array
{
    $constants = getPetConstants();
    $custom = $constants['custom_pet_leveling'][$type] ?? [];
    $offset = (int) ($custom['rarity_offset'][$tier] ?? $constants['pet_rarity_offset'][$tier] ?? 0);
    $steps = array_slice($constants['pet_levels'] ?? [], $offset, 99);
    $maxLevel = 100;
    if (($custom['type'] ?? 0) === 1 && is_array($custom['pet_levels'] ?? null)) {
        $steps = array_merge($steps, $custom['pet_levels']);
        $maxLevel = (int) ($custom['max_level'] ?? 200);
    }

    return [$steps, $maxLevel];
}

// [level, maxLevel, percent towards the next level] for a pet with $exp total xp.
function getPetLevel(string $type, string $tier, float $exp): array
{
    [$steps, $maxLevel] = getPetSteps($type, $tier);

    $level = 1;
    $remaining = $exp;
    foreach ($steps as $needed) {
        if ($level >= $maxLevel || $remaining < $needed) {
            return [$level, $maxLevel, $level >= $maxLevel ? 100.0 : $remaining / max(1, $needed) * 100];
        }
        $remaining -= $needed;
        $level++;
    }

    return [$level, $maxLevel, 100.0];
}

function estimatePetValue(string $type, string $tier, string $name, float $exp, int $level, mixed $heldItem, mixed $skin): ?array
{
    $listing = getLowestBinPrices()['pets'][$tier . '|' . $name] ?? null;
    if ($listing === null) {
        return null;
    }

    if ($level >= 200 && isset($listing['l200'])) {
        $base = (float) $listing['l200'];
    } elseif ($level >= 100 && isset($listing['l100'])) {
        $base = (float) $listing['l100'];
    } elseif ($level < 100 && isset($listing['l100'])) {
        [$steps] = getPetSteps($type, $tier);
        $toHundred = max(1.0, (float) array_sum(array_slice($steps, 0, 99)));
        $low = min((float) ($listing['l1'] ?? 0), (float) $listing['l100']); // a level 1 listing can be an outlier
        $base = $low + ((float) $listing['l100'] - $low) * min(1.0, $exp / $toHundred);
    } elseif (isset($listing['l1'])) {
        $base = (float) $listing['l1']; // only level 1 pets are listed: their price is a floor
    } else {
        return null;
    }

    $parts = ['Pet (level ' . $level . ')' => $base];
    $unpriced = [];
    $info = getSkyblockItemInfo();
    foreach ([[$heldItem, ''], [is_string($skin) && $skin !== '' ? 'PET_SKIN_' . strtoupper($skin) : null, '']] as [$itemId]) {
        if (is_string($itemId) && $itemId !== '') {
            $price = getItemPrice($itemId);
            $label = $info[$itemId]['name'] ?? ucwords(strtolower(str_replace('_', ' ', $itemId)));
            if ($price === null) {
                $unpriced[] = $label;
            } else {
                $parts[$label] = $price;
            }
        }
    }

    return ['total' => (int) round(array_sum($parts)), 'parts' => $parts, 'unpriced' => $unpriced];
}

/** A pet entry of `pets_data.pets` => its value, or null when no such pet is listed on the Auction House. */
function estimatePetFromApi(array $pet): ?array
{
    $type = (string) ($pet['type'] ?? '');
    $tier = (string) ($pet['tier'] ?? 'COMMON');
    if ($type === '') {
        return null;
    }
    $exp = max(0.0, (float) ($pet['exp'] ?? 0));
    [$level] = getPetLevel($type, $tier, $exp);
    $name = getPetConstants()['id_to_display_name'][$type] ?? ucwords(strtolower(str_replace('_', ' ', $type)));

    $estimate = estimatePetValue($type, $tier, $name, $exp, $level, $pet['heldItem'] ?? null, $pet['skin'] ?? null);

    return $estimate !== null ? ['name' => '[Lvl ' . $level . '] ' . $name] + $estimate : null;
}

// ---------------------------------------------------------------------------------------------------------------------
// Reading inventories and putting it all together
// ---------------------------------------------------------------------------------------------------------------------

/**
 * A gzipped, base64 NBT inventory (`inv_contents.data`, `ender_chest_contents.data`, ...) => one entry per slot:
 * null for an empty slot, otherwise ['name', 'count', 'lore' (colour-coded lines), 'value' (estimateItemValue() or null)].
 */
function decodeInventory(?string $encodedNbt): array
{
    $compressed = is_string($encodedNbt) && $encodedNbt !== '' ? base64_decode($encodedNbt, true) : false;
    if (!is_string($compressed)) {
        return [];
    }

    try {
        $root = \Aternos\Nbt\Tag\Tag::load(new \Aternos\Nbt\IO\Reader\GZipCompressedStringReader($compressed, \Aternos\Nbt\NbtFormat::JAVA_EDITION));
        $itemList = $root instanceof \Aternos\Nbt\Tag\CompoundTag ? $root->getList('i') : null;
        if ($itemList === null) {
            return [];
        }

        $slots = array_fill(0, $itemList->count(), null);
        foreach ($itemList as $index => $itemTag) {
            $count = $itemTag instanceof \Aternos\Nbt\Tag\CompoundTag ? $itemTag->get('Count')?->getValue() : null;
            if (!is_numeric($count) || (int) $count < 1) {
                continue;
            }

            $tag = $itemTag->getCompound('tag');
            $display = $tag?->getCompound('display');
            $name = $display?->getString('Name')?->getValue() ?? '';
            $lore = [];
            foreach ($display?->getList('Lore') ?? [] as $line) {
                if ($line instanceof \Aternos\Nbt\Tag\StringTag) {
                    $lore[] = $line->getValue();
                }
            }

            try {
                $value = estimateItemValue($tag?->getCompound('ExtraAttributes'), (int) $count); // a pricing problem must not hide the item
            } catch (\Throwable) {
                $value = null;
            }
            $slots[(int) $index] = ['name' => $name, 'count' => (int) $count, 'lore' => $lore, 'value' => $value];
        }

        return $slots;
    } catch (\Throwable) {
        return [];
    }
}

/** Essence stack => coins: amount x the Bazaar price of that essence. */
function calculateEssenceValue(array $member): float
{
    $total = 0.0;
    foreach ($member['currencies']['essence'] ?? [] as $type => $entry) {
        $amount = is_array($entry) ? (float) ($entry['current'] ?? 0) : 0.0;
        $price = $amount > 0 ? getItemPrice('ESSENCE_' . strtoupper((string) $type)) : null;
        $total += $price !== null ? $price * $amount : 0.0;
    }

    return $total;
}

/**
 * The stored armor and equipment sets of the player's loadouts, each counted once however many loadouts use it. The set
 * that is currently worn is skipped: its items are already in inv_armor / equipment_contents.
 *
 * @return list<array> decoded slots (see decodeInventory())
 */
function decodeLoadoutItems(array $member): array
{
    $loadout = $member['loadout'] ?? null;
    if (!is_array($loadout) || !is_array($loadout['loadouts'] ?? null)) {
        return [];
    }

    $items = [];
    $seen = [];
    foreach ($loadout['loadouts'] as $entry) {
        foreach (['armor' => 'armor_set_id', 'equipment' => 'equipment_set_id'] as $section => $idField) {
            $setId = $entry[$idField] ?? null;
            if (!is_numeric($setId) || isset($seen[$section][(int) $setId]) || (int) ($loadout[$section]['equipped_set'] ?? -1) === (int) $setId) {
                continue;
            }
            $seen[$section][(int) $setId] = true;
            foreach ($loadout[$section][(string) (int) $setId] ?? [] as $key => $piece) {
                if ($key !== 'id' && is_array($piece)) {
                    array_push($items, ...decodeInventory($piece['data'] ?? null));
                }
            }
        }
    }

    return $items;
}

/**
 * @param array $member `profile.members.<uuid>` of the Hypixel API
 * @param float $bank   the coin balance of the profile's bank (`profile.banking.balance`), 0 if the bank API is off
 * @return array{total: float, purse: float, bank: float, categories: array<string, float>, top: list<array{name: string, value: int}>, unpriced: list<string>}
 */
function calculatePlayerNetworth(array $member, float $bank = 0.0): array
{
    $inventory = is_array($member['inventory'] ?? null) ? $member['inventory'] : [];
    $groups = [
        'Armor' => decodeInventory($inventory['inv_armor']['data'] ?? null),
        'Equipment' => decodeInventory($inventory['equipment_contents']['data'] ?? null),
        'Inventory' => decodeInventory($inventory['inv_contents']['data'] ?? null),
        'Ender chest' => decodeInventory($inventory['ender_chest_contents']['data'] ?? null),
        'Personal vault' => decodeInventory($inventory['personal_vault_contents']['data'] ?? null),
        'Wardrobe' => decodeInventory($inventory['wardrobe_contents']['data'] ?? null),
        'Loadouts' => decodeLoadoutItems($member),
    ];
    foreach ($inventory['bag_contents'] ?? [] as $bagName => $bag) {
        $label = $bagName === 'talisman_bag' ? 'Accessory bag' : 'Bags';
        $groups[$label] = array_merge($groups[$label] ?? [], decodeInventory($bag['data'] ?? null));
    }
    foreach ($inventory['backpack_contents'] ?? [] as $backpack) {
        $groups['Backpacks'] = array_merge($groups['Backpacks'] ?? [], decodeInventory($backpack['data'] ?? null));
    }

    $categories = [];
    $valued = [];
    $unpriced = [];
    foreach ($groups as $label => $slots) {
        $categories[$label] = 0.0;
        foreach ($slots as $slot) {
            if (is_array($slot) && $slot['value'] !== null) {
                $categories[$label] += $slot['value']['total'];
                $valued[] = ['name' => trim(preg_replace('/§./u', '', $slot['name']) ?? $slot['name']), 'value' => $slot['value']['total']];
                array_push($unpriced, ...$slot['value']['unpriced']);
            }
        }
    }

    $categories['Pets'] = 0.0;
    foreach ($member['pets_data']['pets'] ?? [] as $pet) {
        $estimate = is_array($pet) ? estimatePetFromApi($pet) : null;
        if ($estimate !== null) {
            $categories['Pets'] += $estimate['total'];
            $valued[] = ['name' => $estimate['name'], 'value' => $estimate['total']];
        }
    }
    $categories['Essence'] = calculateEssenceValue($member);

    usort($valued, static fn(array $a, array $b): int => $b['value'] <=> $a['value']);
    $purse = (float) ($member['currencies']['coin_purse'] ?? 0);

    return [
        'total' => $purse + $bank + array_sum($categories),
        'purse' => $purse,
        'bank' => $bank,
        'categories' => array_filter($categories, static fn(float $value): bool => $value > 0),
        'top' => array_slice($valued, 0, 10),
        'unpriced' => array_values(array_unique($unpriced)),
    ];
}

/** Colour-coded lore lines for a networth tooltip (feed them to minecraftTooltipHtml() from lore.php). */
function networthTooltipLines(array $result, int $topCount = 5): array
{
    $lines = ['§7Bank: §6' . number_format($result['bank']) . ' coins', '§7Purse: §6' . number_format($result['purse']) . ' coins', ''];
    foreach ($result['categories'] as $label => $total) {
        $lines[] = '§7' . $label . ': §6' . number_format($total) . ' coins';
    }
    $lines[] = '';
    $lines[] = '§7Top ' . $topCount . ' most expensive items:';
    foreach (array_slice($result['top'], 0, $topCount) as $position => $item) {
        $lines[] = '§8' . ($position + 1) . '. §f' . $item['name'] . ' §8- §6' . formatRoundedCoins($item['value']) . ' coins';
    }

    return $lines;
}

// `php networth.php refresh`: rebuild the Bazaar and Auction House caches from a separate process.
if (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === 'refresh' && realpath($argv[0] ?? '') === __FILE__) {
    require_once __DIR__ . '/vendor/autoload.php';
    buildBazaarPrices(NETWORTH_CACHE_DIR . '/bazaar.json');
    buildLowestBinPrices(NETWORTH_CACHE_DIR . '/auction-lowest-bin.json');
    @unlink(NETWORTH_CACHE_DIR . '/market-refresh.lock');
}
