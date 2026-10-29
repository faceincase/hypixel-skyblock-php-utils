<div align="center">

# Hypixel Skyblock PHP Utils

**Minecraft-style item lore tooltips and live networth calculation for Hypixel SkyBlock, in plain PHP.**

![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-vanilla-F7DF1E?logo=javascript&logoColor=black)
![CSS](https://img.shields.io/badge/CSS-custom%20properties-1572B6?logo=css3&logoColor=white)
![Composer](https://img.shields.io/badge/Composer-aternos%2Fnbt-885630?logo=composer&logoColor=white)
![Hypixel API](https://img.shields.io/badge/Hypixel-API%20v2-FFAA00)
![Minecraft](https://img.shields.io/badge/Minecraft-lore%20%C2%A7%20codes-62B47A)
![Size](https://img.shields.io/badge/size-58.93%20KB-informational)
![Discord](https://img.shields.io/badge/Discord-fac.e-5865F2?logo=discord&logoColor=white)

[What's inside](#whats-inside) · [Lore tooltips](#lorephp-normal-lore-vs-pretty-lore) · [Networth](#networthphp-how-networth-is-calculated) · [Need help?](#need-help-or-a-custom-project)

</div>

<p align="center"><sub>All screenshots were taken in the browser, running the lightweight PHP utility.</sub></p>
<table>
  <tr>
    <th>DEFAULT LORE</th>
    <th>PRETTY LORE (by Face)</th>
  </tr>
  <tr>
    <td align="center"><img width="420" alt="Before: first screen" src="https://github.com/user-attachments/assets/fe19234f-2f19-49f9-b3c6-44fa6f5f5e5c" /></td>
    <td align="center"><img width="420" alt="After: first screen" src="https://github.com/user-attachments/assets/eb4314f3-417a-4cd6-9329-ee40108dc137" /></td>
  </tr>
  <tr>
    <td align="center" valign="top"><img height="600" alt="Before: second screen" src="https://github.com/user-attachments/assets/492cf10b-16ff-4e35-be4f-d1c7f55a4428" /></td>
    <td align="center" valign="top"><img height="600" alt="After: second screen" src="https://github.com/user-attachments/assets/f5984693-bb6b-40ea-baa5-0ad7b0d422a0" /></td>
  </tr>
</table>

## What's inside

Two small, dependency-light PHP files taken from a Hypixel SkyBlock profile viewer:

| File | What it does | Size |
| --- | --- | --- |
| [`lore.php`](lore.php) | Shows item lore as Minecraft-style tooltips, in a **normal** or a **"pretty"** (decorated) mode | **24.98 KB** |
| [`networth.php`](networth.php) | Estimates the value of every item and pet a player owns and adds it up into a **networth** | **33.94 KB** |
| | **Total** | **58.93 KB** |


## Need help or a custom project?

Stuck setting this up, or want something like it built for your own project? Message me on Discord, I'm happy to help.
<br>
Discord: **`fac.e`** 
<br>
When you write, it helps to include what you are trying to build, a link or a short description of your current code, and any error message.



## `lore.php`: normal lore vs pretty lore

### What you get

- `minecraftTextToHtml()` and `minecraftTooltipHtml()` turn colour-coded lore (`§6Hyperion`, `§7Damage: §c+260`) into HTML.
- `renderLoreTrigger()` makes any element show that tooltip on hover or keyboard focus.
- `loreCss()`, `loreScript()`, `loreTooltipElement()` give you the CSS, the JavaScript and the floating tooltip box.
- `loreToggleButton()` is an optional button that switches **Pretty Lore** on and off (remembered in `localStorage`).

```php
require 'lore.php';
?>
<head><?= loreCss() ?></head>
<body>
  <?= loreToggleButton() ?>
  <?= renderLoreTrigger('Hyperion', '§6Hyperion', [
        '§7Damage: §c+260',
        '§7Strength: §c+150',
        '',
        '§6Ability: Wither Impact  §e§lRIGHT CLICK',
        '§7Teleport §a10 blocks§7 ahead.',
        '§7Mana Cost: §3300',
        '',
        '§6§lLEGENDARY SWORD',
      ], 'legendary') ?>
  <?= loreTooltipElement() ?>
  <?= loreScript() ?>
</body>
```

Options for `renderLoreTrigger()` (its fifth argument): `'data-wide' => true` stops line wrapping, `'data-plain' => true` turns
off the dotted stat lines (use it for list-style tooltips such as a networth breakdown).

### How it works

1. **Colour codes → HTML (PHP).** The text is split on `§x` with `preg_split`. Each piece becomes a `<span>`: a colour code
   gives it a class `mc-c0`…`mc-cf` (the sixteen Minecraft colours, each also defines a darker shadow colour), `§l §o §n §m` add
   bold/italic/underline/strike classes, `§k` marks obfuscated text, `§r` resets. A colour code clears earlier formatting,
   like the game does. Text is passed through `htmlspecialchars`, so lore from the API cannot inject HTML.
2. **One tooltip element, many templates.** Every item keeps its tooltip HTML inside a `<template class="mc-tip-source">`.
   The script listens to `mousemove` / `focusin` once on the document, finds the closest `.has-mc-tip`, copies the template
   into the single `#mc-tooltip` box and positions it next to the cursor (flipping to the left or clamping at the screen
   edges). That keeps the page light, even with thousands of items.
3. **Minecraft look (CSS).** A pixel font (`Minecraft`, loaded from jsDelivr), a text shadow in the darker colour of each
   code (`0.125em` offset, exactly one font pixel), a gradient border in the item's rarity colour (`data-rarity`), and a
   double-shadow for bold, as the game draws it.
4. **Obfuscated text.** `§k` text is replaced by random characters of similar width every 50 ms while the tooltip is open.
5. **Pretty mode (JavaScript).** With the toggle on, a function called `prettify()` reshapes the copied tooltip DOM before it is
   shown. The normal HTML is never changed, so turning the mode off just stops running it:
   - the name gets a centered style and a `✦` divider below it;
   - the rarity footer (`LEGENDARY SWORD`) is removed from the bottom and shown as a small bordered tag under the name,
     and `Breaking Power 9` becomes a green `BP 9` tag;
   - blank lines become small gaps, and real gray dividers only appear in a few places (before the first ability and before
     the value line), so the tooltip is not cut into pieces; two separators never touch and none is left at the end;
   - **stat lines** (`Damage: +75`) in the top block become `Damage  . . . . .  +75` with a `▸` marker. Only the first block
     is touched, so `Mana Cost: 40` inside an ability stays normal;
   - abilities get a `✦` marker, and `Est. item Value:` lines are centered with a `⛁`;
   - `Gemstones: [x] [x]` becomes a labelled divider and a row of pixel-art circles (colored if a gem is in, gray if empty),
     built with CSS `clip-path` polygons on an 8×8 pixel grid;
   - `Fuel: 1,038/3k` (drills) becomes a `Drill Fuel` divider and a pixel progress bar with the amount in the middle.

### What it uses

- PHP: `preg_split`, `htmlspecialchars`, no libraries.
- JavaScript: vanilla (DOM, `<template>`, `localStorage`, `setInterval`), no libraries.
- CSS: custom properties, `color-mix()` and `clip-path`, so it needs a current browser (Chrome/Edge 111+, Firefox 113+, Safari 16.2+).
- One external file: the Minecraft font `minecraft.woff2` from `cdn.jsdelivr.net` (South-Paw/typeface-minecraft). Remove the `@font-face`
  line in the CSS if you do not want that request; the tooltip then falls back to Courier.

### Size breakdown of `lore.php`

| Part | Bytes | KB |
| --- | ---: | ---: |
| PHP functions and comments | 5,001 | 4.88 |
| CSS (inside `loreCss()`) | 6,667 | 6.51 |
| JavaScript (inside `loreScript()`) | 13,915 | 13.59 |
| **File** | **25,583** | **24.98** |

---

## `networth.php`: how networth is calculated

```
networth = purse + bank + essence + Σ item values + Σ pet values
```

### Usage

```bash
composer require aternos/nbt       # reads the gzipped NBT inventories of the API
```

```php
require 'vendor/autoload.php';
require 'networth.php';

// $member = the player's entry in profile['members'] from /v2/skyblock/profile; $bank = profile['banking']['balance']
$result = calculatePlayerNetworth($member, $bank);

echo number_format($result['total']);          // the networth
print_r($result['categories']);                // ['Inventory' => 1234567.0, 'Pets' => ..., ...]
print_r($result['top']);                       // the 10 most valuable items: ['name' => ..., 'value' => ...]
print_r($result['unpriced']);                  // applied parts that could not be priced (counted as 0)

// Tooltip lines, ready for lore.php:
echo minecraftTooltipHtml('§6Networth', networthTooltipLines($result));
```

Other useful functions: `estimateItemValue()` (one item, returns the total and a per-part breakdown), `decodeInventory()` (an API
inventory string → slots with name, lore and value), `getItemPrice()` (one id), `formatRoundedCoins()` (`123M`).
`php networth.php refresh` rebuilds the market caches from the command line. Set `define('NETWORTH_CACHE_DIR', '/path')` before
including the file to choose where the cache is written (default: `./cache`).

### How an item is valued

1. **Base price.** The item's own price: the Bazaar *instant buy* price if the Bazaar has it (multiplied by the stack size),
   otherwise the cheapest **BIN** auction under the item's display name. Dungeon stars (`✪`) and symbols are stripped from
   auction names, and a reforge prefix is dropped as a fallback (`Fabled Hyperion` → `Hyperion`), so a plain item is priced
   from the cheapest copy of any reforge.
2. **Applied parts**, each priced on its own and added:
   - the reforge stone (reforge name → stone id from NotEnoughUpdates data, then its price);
   - recombobulator, other counters (Art of War, Wood Singularity, Farming for Dummies, ...), power-ability scrolls, dyes, drill parts,
     talisman enrichments;
   - hot potato books (first 10) and fuming potato books (the rest);
   - **enchantments**: the Bazaar enchantment book of that level, or if only the level below is sold, twice that price (two books combine). It
     never scales further than one level, since that inflates rare high-level books. Efficiency above V adds one Silex per extra level;
   - **dungeon stars**: the essence and items listed for each star up to the item's level;
   - **gemstones**: the price of each `QUALITY_TYPE_GEM`, plus the coins and items that were spent to unlock gem slots.
3. **Unpriced parts** (nobody sells them) count as 0 and are listed in the result, so a gap is visible and not silently wrong.

### How a pet is valued

The Hypixel API has no pet levels, so the level tables come from the NotEnoughUpdates repo. A pet is valued from the cheapest BIN
listing of the **same pet and rarity** at level 200, 100 or 1 (the level band). Levels in between are interpolated by XP between
the level 1 and the level 100 listing. The held item and the skin are added on top.

### How the total is assembled

`calculatePlayerNetworth()` decodes and values: armor, equipment, inventory, ender chest, personal vault, wardrobe, accessory bag and
other bags, backpacks and **loadouts** (the stored armor/equipment sets of every loadout, each set counted **once** however many
loadouts use it; the worn set is skipped because it is already in your armor and equipment). Then pets, then essence (amount ×
the Bazaar price of that essence), and finally purse and bank. Sacks are not included.

### Where the data comes from, and what is cached

Everything is cached on disk (`NETWORTH_CACHE_DIR`) as JSON with `info`, `source` and `fetched_at`, so a file tells you what it is.

| Data | Source | Cache file | Lifetime | Typical size |
| --- | --- | --- | --- | ---: |
| Bazaar prices (buy/sell only) | Hypixel `/v2/skyblock/bazaar` | `bazaar.json` | 30 min | ~132 KB |
| Cheapest BINs by name, reforge-less alias and pet band | Hypixel `/v2/skyblock/auctions` (all pages) | `auction-lowest-bin.json` | 30 min, stale copy served while rebuilt in the background | ~189 KB |
| Item names, gem slots, star costs | Hypixel `/v2/resources/skyblock/items` | `skyblock-items.json` | 24 h | ~872 KB |
| Reforge name → stone | NotEnoughUpdates-REPO `constants/reforgestones.json` | `reforge-stones.json` | 24 h | ~2 KB |
| Pet level tables, display names | NotEnoughUpdates-REPO `constants/pets.json` | `neu-pets.json` | 24 h | ~4 KB |

The Auction House has to be read page by page (~30 s), so a stale snapshot is served immediately and a detached process
(`php networth.php refresh`, at most every 5 minutes, guarded by `market-refresh.lock`) rebuilds it. If no snapshot exists at all,
the first call waits for it. All requests are plain `file_get_contents`, so `allow_url_fopen` must be on. None of these
endpoints needs an API key.

### Size breakdown of `networth.php`

| Part | Approx. lines |
| --- | ---: |
| Market data (Bazaar, Auction House, item list, reforge stones, caching) | ~190 |
| Item valuation (`estimateItemValue`, enchantments, NBT helper) | ~150 |
| Pets (NEU data, levels, pet value) | ~120 |
| Inventory decoding, loadouts, essence, totals, tooltip lines, CLI refresh | ~230 |
| **File: 795 lines, 34,759 bytes = 33.94 KB** | |

### Known limitations

- Unpriced enchants (for example Compact IX/X, Smelting Touch, Efficiency V without Silex) count as 0.
- A base auction listing can already include upgrades, which may double-count a part. Reforge application fees are not included.
- Pets with no auction listing have no value; level 1 listings that are outliers are clamped.
- The bank is only known if the player's banking API is on; pass 0 otherwise.
- Reforge stones and pet levels are community data (NotEnoughUpdates) and can lag behind the game.
- Prices are "what you would pay now", not "what you would get if you sold".

---

## Credits

- Hypixel SkyBlock data, resource packs and API belong to Hypixel; Minecraft belongs to Mojang. This project is not affiliated
  with either.
- Community data from [NotEnoughUpdates-REPO](https://github.com/NotEnoughUpdates/NotEnoughUpdates-REPO).
- NBT parsing by [aternos/nbt](https://github.com/aternosorg/php-nbt). Pixel font by
  [South-Paw/typeface-minecraft](https://github.com/South-Paw/typeface-minecraft).
