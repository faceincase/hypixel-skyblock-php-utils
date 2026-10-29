<?php

declare(strict_types=1);

/**
 * Item lore as Minecraft-style tooltips, in two flavours:
 *
 *   - normal lore: every lore line becomes one <div> of coloured <span>s (minecraftTooltipHtml()).
 *   - "pretty" lore: the same HTML, restyled in the browser by prettify() (dividers, tags, dotted stat lines, ...).
 *
 * Usage (see README.md):
 *
 *   require 'lore.php';
 *   echo loreCss();                 // once, inside <head> (as a <style> block)
 *   echo loreToggleButton();        // optional on/off button for the pretty mode
 *   echo renderLoreTrigger('Hyperion', '§6Hyperion', ['§7Damage: §c+260', '', '§6§lLEGENDARY'], 'legendary');
 *   echo loreTooltipElement();      // once, before </body>
 *   echo loreScript();              // once, before </body>
 *
 * Everything is plain PHP + vanilla JavaScript/CSS, no dependencies.
 */

/** "§6Hyperion §7[Lvl 5]" => <span class="mc-c6">Hyperion </span><span class="mc-c7">[Lvl 5]</span> */
function minecraftTextToHtml(string $text): string
{
    $styleClasses = ['k' => 'mc-obf', 'l' => 'mc-bold', 'm' => 'mc-strike', 'n' => 'mc-underline', 'o' => 'mc-italic'];
    $parts = preg_split('/§([0-9a-fk-or])/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
    $html = '';
    $color = null;
    $styles = [];

    foreach ($parts as $index => $part) {
        if ($index % 2 === 1) {
            $code = strtolower($part);
            if ($code === 'r') {
                $color = null;
                $styles = [];
            } elseif (isset($styleClasses[$code])) {
                $styles[$code] = $styleClasses[$code];
            } else {
                // A colour code also clears the formatting before it, like in the game.
                $color = $code;
                $styles = [];
            }
            continue;
        }

        if ($part === '') {
            continue;
        }

        $classes = $styles;
        if ($color !== null) {
            $classes[] = 'mc-c' . $color;
        }
        $html .= '<span' . ($classes !== [] ? ' class="' . implode(' ', $classes) . '"' : '') . '>'
            . htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
    }

    return $html;
}

/** The inner HTML of one tooltip: the name line, then one .mc-tip-line per lore line (an empty line stays a blank row). */
function minecraftTooltipHtml(string $formattedName, array $formattedLore): string
{
    $html = '<div class="mc-tip-line">' . minecraftTextToHtml($formattedName) . '</div>';
    foreach ($formattedLore as $loreLine) {
        $lineHtml = minecraftTextToHtml((string) $loreLine);
        $html .= '<div class="mc-tip-line">' . ($lineHtml !== '' ? $lineHtml : '&nbsp;') . '</div>';
    }

    return $html;
}

/**
 * An element that shows the item's tooltip on hover or keyboard focus. $label is the visible text (HTML-escaped here),
 * $rarity ('common' ... 'special') picks the tooltip border colour, $attributes adds e.g. 'data-wide' => true (no
 * wrapping) or 'data-plain' => true (never apply the dotted stat lines, for list-style tooltips).
 */
function renderLoreTrigger(string $label, string $formattedName, array $formattedLore, string $rarity = '', array $attributes = []): string
{
    $extra = '';
    foreach ($attributes as $name => $value) {
        if ($value !== false && $value !== null) {
            $extra .= ' ' . htmlspecialchars((string) $name, ENT_QUOTES) . ($value === true ? '' : '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"');
        }
    }

    return '<span class="has-mc-tip" tabindex="0" data-rarity="' . htmlspecialchars($rarity, ENT_QUOTES) . '"' . $extra . '>'
        . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '<template class="mc-tip-source">' . minecraftTooltipHtml($formattedName, $formattedLore) . '</template></span>';
}

/** The single floating tooltip box that the script fills and moves; output it once. */
function loreTooltipElement(): string
{
    return '<div id="mc-tooltip" class="mc-tooltip" role="tooltip"></div>';
}

/** A button that switches the pretty mode on and off (remembered in the browser). */
function loreToggleButton(string $label = 'Pretty Lore'): string
{
    return '<button type="button" id="pretty-toggle" class="pretty-toggle" aria-pressed="false" title="Decorate item tooltips with separators and ornaments">'
        . '<span aria-hidden="true">✦</span> <span class="pretty-label">' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span></button>';
}

/** All CSS the tooltips need, wrapped in <style>: colour codes, the tooltip box, the pretty-mode classes and a pixel font. */
function loreCss(): string
{
    return "<style>\n" . <<<'EOT_CSS'
@font-face { font-family: Minecraft; font-display: swap; src: url("https://cdn.jsdelivr.net/gh/South-Paw/typeface-minecraft@master/files/minecraft.woff2") format("woff2"), url("https://cdn.jsdelivr.net/gh/South-Paw/typeface-minecraft@master/files/minecraft.woff") format("woff"); }
.mc-tooltip { --tip-top: #5000ff; --tip-bottom: #28007f; position: fixed; z-index: 50; top: 0; left: 0; max-width: min(420px, calc(100vw - 16px)); border: 2px solid var(--tip-bottom); border-image: linear-gradient(var(--tip-top), var(--tip-bottom)) 1; background: rgba(16, 0, 16, 0.94); padding: 6px 8px; font-family: Minecraft, "Courier New", monospace; font-size: 16px; line-height: 1.25; font-synthesis-weight: none; pointer-events: none; visibility: hidden; }
.mc-tooltip[data-rarity="common"] { --tip-top: #ffffff; --tip-bottom: #7f7f7f; }
.mc-tooltip[data-rarity="uncommon"] { --tip-top: #55ff55; --tip-bottom: #2a7f2a; }
.mc-tooltip[data-rarity="rare"] { --tip-top: #5555ff; --tip-bottom: #2a2a7f; }
.mc-tooltip[data-rarity="epic"] { --tip-top: #aa00aa; --tip-bottom: #550055; }
.mc-tooltip[data-rarity="legendary"] { --tip-top: #ffaa00; --tip-bottom: #7f5500; }
.mc-tooltip[data-rarity="mythic"] { --tip-top: #ff55ff; --tip-bottom: #7f2a7f; }
.mc-tooltip[data-rarity="special"] { --tip-top: #ff5555; --tip-bottom: #7f2a2a; }
.pretty-toggle { display: inline-flex; height: 44px; flex: none; align-items: center; justify-content: center; gap: 7px; border: 1px solid var(--line); border-radius: 8px; background: var(--page); padding: 0 13px; color: #a1a1aa; cursor: pointer; font-size: 13px; font-weight: 600; transition: color 140ms ease, border-color 140ms ease, background 140ms ease; }
.pretty-toggle:hover { color: #f4f4f5; }
.pretty-toggle[aria-pressed="true"] { border-color: rgba(255, 170, 0, 0.7); background: rgba(255, 170, 0, 0.12); color: #ffaa00; }
.mc-tooltip.is-pretty { min-width: 230px; padding: 9px 14px 10px; background: linear-gradient(180deg, rgba(30, 6, 42, 0.97), rgba(10, 0, 16, 0.97)); box-shadow: 0 0 16px color-mix(in srgb, var(--tip-top) 40%, transparent), 0 6px 22px rgba(0, 0, 0, 0.55); }
.mc-tooltip.is-pretty .pretty-title { text-align: center; font-size: 18px; letter-spacing: 0.02em; }
.mc-tooltip.is-pretty .pretty-divider { display: flex; align-items: center; gap: 8px; margin: 5px 0; color: #8a8a8a; font-size: 11px; line-height: 1; text-shadow: none; }
.mc-tooltip.is-pretty .pretty-divider::before, .mc-tooltip.is-pretty .pretty-divider::after { height: 2px; flex: 1; background: linear-gradient(90deg, transparent, #555 35%, #8a8a8a); content: ""; }
.mc-tooltip.is-pretty .pretty-divider::after { transform: scaleX(-1); }
.mc-tooltip.is-pretty { max-width: min(560px, calc(100vw - 16px)); }
.mc-tooltip.is-pretty .pretty-stat { display: flex; align-items: baseline; white-space: nowrap; }
.mc-tooltip.is-pretty .pretty-stat::before { margin-right: 6px; color: var(--tip-top); content: "▸"; }
.mc-tooltip.is-pretty .pretty-stat-fill { min-width: 12px; flex: 1 1 auto; margin: 0 0.5em; overflow: hidden; direction: rtl; color: #555; text-shadow: none; }
.mc-tooltip.is-pretty .pretty-stat-value { flex: none; }
.mc-tooltip.is-pretty .pretty-divider.is-label { color: #8a8a8a; font-family: Minecraft, "Courier New", monospace; font-size: 16px; letter-spacing: 0.04em; text-shadow: 0.125em 0.125em 0 #2a2a2a; }
.mc-tooltip.is-pretty .pretty-gap { height: 6px; }
.mc-tooltip.is-pretty .pretty-tags { display: flex; flex-wrap: wrap; justify-content: center; gap: 6px; margin: 4px 0 2px; }
.mc-tooltip.is-pretty .pretty-tag { border: 1px solid var(--tag); background: color-mix(in srgb, var(--tag) 22%, #0c0012); padding: 1px 7px; color: var(--tag); font-size: 14px; letter-spacing: 0.06em; line-height: 1.25; text-shadow: none; }
.mc-tooltip.is-pretty .pretty-gems { display: flex; justify-content: center; gap: 10px; margin: 8px 0 4px; }
.mc-tooltip.is-pretty .pretty-gem { --px: 3px; display: inline-block; width: calc(var(--px) * 8); height: calc(var(--px) * 8); background: #555; clip-path: polygon(25% 0, 75% 0, 75% 12.5%, 87.5% 12.5%, 87.5% 25%, 100% 25%, 100% 75%, 87.5% 75%, 87.5% 87.5%, 75% 87.5%, 75% 100%, 25% 100%, 25% 87.5%, 12.5% 87.5%, 12.5% 75%, 0 75%, 0 25%, 12.5% 25%, 12.5% 12.5%, 25% 12.5%); font-size: 0; image-rendering: pixelated; }
.mc-tooltip.is-pretty .pretty-gem.is-filled { background: var(--gem); }
.mc-tooltip.is-pretty .pretty-ability::before { margin-right: 6px; color: #ffaa00; content: "✦"; }
.mc-tooltip.is-pretty .pretty-value { text-align: center; }
.mc-tooltip.is-pretty .pretty-bar { position: relative; height: 22px; margin: 3px 0 5px; border: 2px solid #8b8b8b; background: #2b2b2b; box-shadow: inset 2px 2px 0 #1a1a1a, inset -2px -2px 0 #3a3a3a; image-rendering: pixelated; }
.mc-tooltip.is-pretty .pretty-bar-fill { position: absolute; inset: 0 auto 0 0; background: #00aa00; }
.mc-tooltip.is-pretty .pretty-bar-label { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 11px; line-height: 1; text-shadow: 0.125em 0.125em 0 #3f3f3f; }
.mc-tooltip.is-pretty .pretty-value::before { margin-right: 6px; color: #ffaa00; content: "⛁"; }
.mc-tooltip[data-wide] { max-width: calc(100vw - 16px); white-space: nowrap; }
.mc-tooltip.is-visible { visibility: visible; }
.mc-tooltip span { color: var(--mc, #fff); text-shadow: 0.125em 0.125em 0 var(--mc-shadow, #3f3f3f); }
.mc-tip-line { min-height: 1.15em; white-space: pre-wrap; overflow-wrap: anywhere; }
.mc-tip-line:first-child { margin-bottom: 2px; }
.mc-c0 { --mc: #000000; --mc-shadow: #000000; }
.mc-c1 { --mc: #0000AA; --mc-shadow: #00002A; }
.mc-c2 { --mc: #00AA00; --mc-shadow: #002A00; }
.mc-c3 { --mc: #00AAAA; --mc-shadow: #002A2A; }
.mc-c4 { --mc: #AA0000; --mc-shadow: #2A0000; }
.mc-c5 { --mc: #AA00AA; --mc-shadow: #2A002A; }
.mc-c6 { --mc: #FFAA00; --mc-shadow: #2A2A00; }
.mc-c7 { --mc: #AAAAAA; --mc-shadow: #2A2A2A; }
.mc-c8 { --mc: #555555; --mc-shadow: #151515; }
.mc-c9 { --mc: #5555FF; --mc-shadow: #15153F; }
.mc-ca { --mc: #55FF55; --mc-shadow: #153F15; }
.mc-cb { --mc: #55FFFF; --mc-shadow: #153F3F; }
.mc-cc { --mc: #FF5555; --mc-shadow: #3F1515; }
.mc-cd { --mc: #FF55FF; --mc-shadow: #3F153F; }
.mc-ce { --mc: #FFFF55; --mc-shadow: #3F3F15; }
.mc-cf { --mc: #FFFFFF; --mc-shadow: #3F3F3F; }
.mc-tooltip .mc-bold { text-shadow: 0.0625em 0 0 var(--mc, #fff), 0.125em 0.125em 0 var(--mc-shadow, #3f3f3f), 0.1875em 0.125em 0 var(--mc-shadow, #3f3f3f); }
.mc-italic { font-style: italic; }
.mc-underline { text-decoration-line: underline; }
.mc-strike { text-decoration-line: line-through; }
.mc-underline.mc-strike { text-decoration-line: underline line-through; }
EOT_CSS . "\n</style>\n";
}

/** The JavaScript, wrapped in <script>: hover/focus handling, obfuscated text, the pretty-mode transformation, the toggle. */
function loreScript(): string
{
    return "<script>\n" . <<<'EOT_JS'
(() => {
    const tooltip = document.getElementById('mc-tooltip');
    let activeSlot = null;
    let scrambleTimer = null;

    // Obfuscated text (§k) swaps each character for a random one of similar width, many times a second.
    const narrow = "il!.,:;'|`";
    const wide = 'mwMW@#%&';
    const normal = 'abcdefghjkmnopqrstuvxyzABCDEFGHJKLNOPQRSTUVXYZ0123456789?$*+=/\<>~^';
    const pool = (character) => narrow.includes(character) ? narrow : wide.includes(character) ? wide : normal;
    const randomLike = (character) => {
        if (character === ' ') return character;
        const chars = pool(character);
        return chars[Math.floor(Math.random() * chars.length)];
    };
    const startScramble = () => {
        clearInterval(scrambleTimer);
        scrambleTimer = null;
        const spans = [...tooltip.querySelectorAll('.mc-obf')];
        if (spans.length === 0) return;
        spans.forEach((span) => { span.dataset.original = span.textContent; });
        scrambleTimer = setInterval(() => {
            spans.forEach((span) => {
                span.textContent = [...span.dataset.original].map(randomLike).join('');
            });
        }, 50);
    };

    // "Pretty Lore": blank lines become ornamental dividers and stat, ability, value and rarity lines get decorators.
    const makeDivider = (ornament = '◆', isLabel = false) => {
        const divider = document.createElement('div');
        divider.className = 'pretty-divider' + (isLabel ? ' is-label' : '');
        divider.textContent = ornament;
        return divider;
    };
    const colorOf = (node) => getComputedStyle(node.nodeType === 1 ? node : node.parentElement).getPropertyValue('--mc').trim() || '#aaaaaa';

    // "Gemstones: [x] [x] [x]" => a labelled divider and a centred row of gem spheres. The brackets carry the
    // gem quality colour, the inside carries the gem colour (grey when the slot is empty, a star when locked).
    const buildGems = (line) => {
        const row = document.createElement('div');
        row.className = 'pretty-gems';
        let slot = null;
        [...line.childNodes].forEach((node) => {
            const text = node.textContent;
            if (text.includes('[') && text.includes(']')) {
                slot = null;
                row.append(makeGem(colorOf(node), colorOf(node), []));
            } else if (text.includes('[')) {
                slot = { ring: colorOf(node), inner: [] };
            } else if (slot && text.includes(']')) {
                row.append(makeGem(slot.ring, slot.inner.length ? colorOf(slot.inner[0]) : slot.ring, slot.inner));
                slot = null;
            } else if (slot) {
                slot.inner.push(node);
            }
        });
        return row;
    };
    const makeGem = (ring, core, innerNodes) => {
        const gem = document.createElement('span');
        const symbol = innerNodes.map((node) => node.textContent).join('').trim();
        const state = symbol === '✦' ? 'is-locked' : core.toLowerCase() === '#555555' ? 'is-empty' : 'is-filled';
        gem.className = 'pretty-gem ' + state;
        gem.style.setProperty('--ring', ring);
        gem.style.setProperty('--gem', core);
        if (state !== 'is-filled') innerNodes.forEach((node) => gem.append(node.cloneNode(true)));
        return gem;
    };

    // "Damage: +75" => label, a run of dots, then the value, so every stat line fills the same width.
    const buildStat = (line) => {
        const label = document.createElement('span');
        const value = document.createElement('span');
        const filler = document.createElement('span');
        label.className = 'pretty-stat-label';
        value.className = 'pretty-stat-value';
        filler.className = 'pretty-stat-fill';
        filler.textContent = '. '.repeat(40);
        let split = false;
        [...line.childNodes].forEach((node) => {
            if (split) { value.append(node); return; }
            const text = node.textContent;
            const at = text.indexOf(':');
            if (at === -1) { label.append(node); return; }
            split = true;
            const before = node.nodeType === 1 ? node.cloneNode(false) : document.createElement('span');
            const after = node.nodeType === 1 ? node.cloneNode(false) : document.createElement('span');
            before.textContent = text.slice(0, at);
            after.textContent = text.slice(at + 1).replace(/^\s+/, '');
            label.append(before);
            if (after.textContent) value.append(after);
        });
        line.replaceChildren(label, filler, value);
    };
    // "Fuel: 1,038/3k" => the caption stays, the numbers move into a full-width pixel progress bar.
    const fuelPattern = /^(?:Drill )?Fuel:\s*[\d,.]+\s*\/\s*[\d,.]+\s*[kKmM]?$/;
    const parseAmount = (raw) => {
        const match = raw.trim().match(/^([\d,.]+)\s*([kKmM]?)$/);
        if (!match) return 0;
        const scale = { '': 1, k: 1e3, m: 1e6 }[match[2].toLowerCase()];
        return parseFloat(match[1].replace(/,/g, '')) * scale;
    };
    const buildFuelBar = (line, text) => {
        const [current, total] = text.slice(text.indexOf(':') + 1).split('/').map((part) => part.trim());
        const max = parseAmount(total);
        const ratio = max > 0 ? Math.min(1, Math.max(0, parseAmount(current) / max)) : 0;
        const bar = document.createElement('div');
        const fill = document.createElement('div');
        const label = document.createElement('span');
        bar.className = 'pretty-bar';
        fill.className = 'pretty-bar-fill';
        fill.style.width = (ratio * 100).toFixed(2) + '%';
        label.className = 'pretty-bar-label';
        label.textContent = current + '/' + total;
        bar.append(fill, label);
        line.replaceWith(makeDivider('Drill Fuel', true), bar);
    };
    const visibleText = (line) => {
        const clone = line.cloneNode(true);
        clone.querySelectorAll('.mc-obf').forEach((node) => node.remove());
        return clone.textContent.replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
    };
    const makeGap = () => {
        const gap = document.createElement('div');
        gap.className = 'pretty-gap';
        return gap;
    };
    const makeTag = (label, color) => {
        const tag = document.createElement('span');
        tag.className = 'pretty-tag';
        tag.style.setProperty('--tag', color);
        tag.textContent = label;
        return tag;
    };
    const abilityStart = /^(Ability|Full Set Bonus|Piece Bonus|Item Ability)\b/;

    // plain: a list-style tooltip (networth) whose lines must not get dotted stat fillers; every blank line is a divider there.
    const prettify = (plain = false) => {
        const isLine = (node) => node.classList.contains('mc-tip-line');
        let lines = [...tooltip.children].filter(isLine);
        if (lines.length === 0) return;
        const title = lines[0];
        const tags = [];

        // The rarity footer (the last text line before the value block) and Breaking Power become tags under the name.
        const valueAt = lines.findIndex((line, index) => index > 0 && /^Est\. item Value:/.test(visibleText(line)));
        for (let i = (valueAt === -1 ? lines.length : valueAt) - 1; i > 0; i--) {
            const text = visibleText(lines[i]);
            if (text === '') continue;
            if (/^[^a-z]*[A-Z]{3,}[^a-z]*$/.test(text)) {
                const coloured = lines[i].querySelector('span:not(.mc-obf)');
                tags.push(makeTag(text.replace(/^[^A-Z]+|[^A-Z]+$/g, ''), coloured ? colorOf(coloured) : '#aaaaaa'));
                lines[i].remove();
            }
            break;
        }
        lines.forEach((line, index) => {
            const power = index > 0 ? visibleText(line).match(/^Breaking Power (\d+)/) : null;
            if (power) {
                tags.push(makeTag('BP ' + power[1], '#55ff55'));
                line.remove();
            }
        });

        title.classList.add('pretty-title');
        let header = title;
        if (tags.length > 0) {
            const row = document.createElement('div');
            row.className = 'pretty-tags';
            row.append(...tags);
            title.after(row);
            header = row;
        }

        const body = [...tooltip.children].filter(isLine).slice(1);
        while (body.length > 0 && visibleText(body[0]) === '') body.shift().remove();
        if (body.length > 0) header.after(makeDivider('✦'));

        // Blank lines are small gaps; only a few boundaries get a real divider (before the first ability, the
        // top list of the networth tooltip, the value and the gemstones), so the tooltip is not cut up.
        let abilityDivided = false;
        // Dotted stat lines are only for the stat block at the top; "Mana Cost: 40" in an ability stays plain.
        let seenStat = false;
        let statsDone = false;
        body.forEach((line, index) => {
            const text = visibleText(line);
            if (text === '') {
                if (seenStat) statsDone = true;
                const next = body.slice(index + 1).map(visibleText).find((candidate) => candidate !== '') ?? '';
                const startsAbility = abilityStart.test(next);
                const major = /^Est\. item Value:/.test(next) || plain || (startsAbility && !abilityDivided);
                if (startsAbility) abilityDivided = true;
                line.replaceWith(major ? makeDivider() : makeGap());
            } else if (/^Est\. item Value:/.test(text)) {
                line.classList.add('pretty-value');
            } else if (/^Gemstones:/.test(text)) {
                line.before(makeDivider('Gemstones', true));
                line.after(makeDivider());
                line.replaceWith(buildGems(line));
            } else if (fuelPattern.test(text)) {
                buildFuelBar(line, text);
            } else if (abilityStart.test(text)) {
                line.classList.add('pretty-ability');
            } else if (!plain && !statsDone && /^[A-Za-z' ]{2,28}:\s*[+\-]?[\d\[(]/.test(text)) {
                seenStat = true;
                line.classList.add('pretty-stat');
                buildStat(line);
            }
        });

        // Never two separators in a row (the stronger one stays), and none at the end.
        const rank = (separator) => separator.classList.contains('is-label') ? 3 : separator.classList.contains('pretty-divider') ? 2 : 1;
        tooltip.querySelectorAll('.pretty-divider, .pretty-gap').forEach((separator) => {
            if (!separator.isConnected) return;
            if (!separator.nextElementSibling) {
                separator.remove();
                return;
            }
            const before = separator.previousElementSibling;
            if (before && before.matches('.pretty-divider, .pretty-gap')) {
                (rank(before) > rank(separator) ? separator : before).remove();
            }
        });
    };
    const prettyEnabled = () => document.documentElement.dataset.prettyLore === 'on';

    const place = (x, y) => {
        const gap = 12;
        const { width, height } = tooltip.getBoundingClientRect();
        let left = x + gap;
        let top = y - gap - height / 2;
        if (left + width > window.innerWidth - 4) left = Math.max(4, x - gap - width);
        top = Math.max(4, Math.min(top, window.innerHeight - height - 4));
        tooltip.style.transform = `translate(${left}px, ${top}px)`;
    };

    const show = (slot, x, y) => {
        const source = slot.querySelector('template.mc-tip-source');
        if (!source) return;
        if (activeSlot !== slot) {
            tooltip.innerHTML = source.innerHTML;
            tooltip.classList.toggle('is-pretty', prettyEnabled());
            if (prettyEnabled()) prettify(slot.hasAttribute('data-plain'));
            tooltip.dataset.rarity = slot.dataset.rarity ?? '';
            if (slot.hasAttribute('data-wide')) tooltip.dataset.wide = '';
            else delete tooltip.dataset.wide;
            activeSlot = slot;
            startScramble();
        }
        tooltip.classList.add('is-visible');
        place(x, y);
    };

    const hide = () => {
        activeSlot = null;
        clearInterval(scrambleTimer);
        scrambleTimer = null;
        tooltip.classList.remove('is-visible');
    };

    document.addEventListener('mousemove', (event) => {
        const slot = event.target instanceof Element ? event.target.closest('.has-mc-tip') : null;
        slot ? show(slot, event.clientX, event.clientY) : hide();
    });
    document.addEventListener('focusin', (event) => {
        const slot = event.target instanceof Element ? event.target.closest('.has-mc-tip') : null;
        if (!slot) return;
        const rect = slot.getBoundingClientRect();
        show(slot, rect.right, rect.top + rect.height / 2);
    });
    document.addEventListener('focusout', hide);
    document.addEventListener('scroll', hide, true);
    // Toggling Pretty Lore re-renders the open tooltip next time it is shown.
    document.addEventListener('pretty-lore-change', hide);
})();

(() => {
    const button = document.getElementById('pretty-toggle');
    const storageKey = 'lore-pretty-mode';
    const apply = (on) => {
        document.documentElement.dataset.prettyLore = on ? 'on' : 'off';
        button.setAttribute('aria-pressed', String(on));
        document.dispatchEvent(new Event('pretty-lore-change'));
    };
    let saved = false;
    try { saved = localStorage.getItem(storageKey) === 'on'; } catch (error) { /* storage blocked: just not remembered */ }
    apply(saved);
    button.addEventListener('click', () => {
        const on = document.documentElement.dataset.prettyLore !== 'on';
        apply(on);
        try { localStorage.setItem(storageKey, on ? 'on' : 'off'); } catch (error) { /* ignore */ }
    });
})();
EOT_JS . "\n</script>\n";
}
