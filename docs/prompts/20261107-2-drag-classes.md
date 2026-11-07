# Drag and drop anywhere, and block classes

*7 November 2026 · Claude Code (Opus 5.5) · commits `5bd32c0` scan, `57a6f6e` api, `a04baa8` bar ·
follows [20261107-1-blocks.md](20261107-1-blocks.md)*

## Prompts

> nice, can I drag and drop blocks

Two options were offered: (1) reorder among siblings only, (2) drag anywhere, which needs rules because an `<li>`
in a `<div>` is invalid and a card out of its grid loses its layout.

> option2, removing li if not in ul, and specific compatibilty rules. allow viewing of classes too, in order to
> modify, delete if necessary

## Drag and drop

Select a block (click in Edit mode), then drag the **⠿** handle of its toolbar.

- The block under the pointer decides where it goes. Its top or bottom quarter means **before** or **after** it;
  in a row of cards it's the left or right edge, and the line turns vertical. Its middle means **inside** it, at
  the end, shown as a dashed box. Empty space in `<main>` means the end of the page.
- The label under the pointer says where and what happens: `after p · <li> becomes <p>`. It turns **red** with
  the reason when HTML does not allow the drop: `This term + description group stays in its list.`
- If the block does not fit at the hovered block, the blocks around it are tried, before or after them. Dragging
  a stat row over another row's `<dd>` lands it before or after that row.
- The page scrolls near the top and bottom edges. Escape cancels. Touch works, through pointer events and
  `touch-action: none` on the handle.
- On drop: texts being edited are saved, the move is written, and the page reloads with the block selected and
  an **Undo**.

### Compatibility rules (`skrid_drop_fit`, mirrored by `fit()` in the bar)

| Dropped into… | Result |
| --- | --- |
| a list (`ul`, `ol`, `menu`) | `li` as is · `p` **becomes** `li` (its content) · anything else is **wrapped** in `<li>` |
| anywhere else, an `li` / `dt` / `dd` | **loses its tag**: text-only content becomes `<p>…</p>`, block content is kept as is |
| a table (`table`, `thead`, `tbody`, `tfoot`) | `tr` only. A `tr` only goes in a table |
| a `dl`, or a `div` grouping inside a `dl` | `dt` / `dd` (and, in the `dl`, a `div` grouping them). A dt/dd group never leaves its `dl`, because its terms would be orphans |
| an `hgroup` | headings and paragraphs only |
| a `p`, a heading, any non-flow parent | refused: it holds text only |
| inside a text (an editable element) | refused |
| inside a link | no link, button or field inside it (`link-in-link`) |
| inside a `header` / `footer` | no `header` / `footer` (`header-in-header`) |
| inside an `address` | no heading or sectioning element (`in-address`) |
| a page without `<main>` | a `header`/`footer`/`nav` outside any section would become the site chrome: refused (`becomes-chrome`) |
| across PHP code | refused (`php`): it could carry the block in or out of an `if` or a loop |

The server checks everything again; the bar's mirror only colours the indicator.

### Bytes

`skrid_block_drop($s, $scan, $k, $target, $where)`:

1. The insertion point and the separator come from the new neighbours. Before T: T's leading whitespace. After
   T: the same separator, after T. Inside an empty T: the whitespace before `</t>` plus two spaces. Inside a T
   that has blocks: after its last block.
2. `skrid_drop_fit()` gives the bytes to insert, possibly rewrapped.
3. **Re-indentation**: the moved lines take the new depth, but only when every line starts with the old
   indentation, so the change is exactly reversible. Never with `pre`, `textarea`, `script` or `style` inside.
4. The block and the spacing in front of it leave, and the new bytes go in, in one pass on the original offsets.

A drop to where the block already is changes nothing: the API answers `{same: true}` without writing a backup.
**Comments between blocks stay in place**: a block moved away and back may end up on the other side of a
`<!-- Hero End -->`. The test counts those as non-exact round trips. They are valid and lose nothing.

## Classes of a block

Click **.class** in the block toolbar, or the block's name.

- The classes **as written in the file** appear as chips. `view.php` sends them, so classes the site's scripts add
  at runtime, like `is-visible` or `aos-animate`, never sneak into the file.
- A click on a chip removes it (struck through) or brings it back. The field adds classes; Enter adds them, and
  Enter on an empty field applies. New ones are green.
- The page **previews** at once. Only the changed classes are toggled on the element, so runtime classes stay.
- **Apply** rewrites the class attribute and nothing else (`skrid_class_op`, shared with paragraph styles). An
  empty list removes the attribute. Cancel or Escape brings the preview back.
- A class may hold anything but spaces, quotes, `<`, `>` and backticks. So `md:col-span-5`, `text-[var(--ch)]`
  and `[&>p]:mt-2` (written `&amp;`) are fine; `bad"class` is refused in the bar and on the server.

## API

```
POST api.php?action=block {page, hash, block: K, op: "drop", target: T | -1, where: "before"|"after"|"inside"}
POST api.php?action=block {page, hash, block: K, op: "classes", classes: "a b c"}
→ {ok, hash, block, backup, count, became: "" | "li" | "p>li" | "li>p" | "dt>p" | "dd>p" | "unwrap"}  or {same: true}
→ 400 {error: rule code}
```

Activity log: `bloc glissé (li)`, `classes d’un bloc (h1)`.

## Tests

`php tests/block_test.php <site>` has fixed cases for every rule:
- li → p and re-indentation;
- li with blocks → unwrapped;
- p → li, heading → wrapped;
- moving into a section and back into the now-empty grid gives the exact bytes;
- into itself, inside a text, inside a paragraph;
- table / row, the dl rules, link in link, footer in header, heading in address, across PHP;
- classes: change, add, remove, quotes refused.

On real pages it also drops a sample of blocks at five kinds of targets and checks that every accepted drop keeps
every word of the page. Then it drops the block back next to its old neighbour and counts exact round trips.

- 127 pages, 41,603 operations, **0 failures**.
- 13,115 accepted drops.
- Refusals counted per rule (not-container, into-itself, dl-group, link-in-link…).

Browser check (Playwright, copy of the youlvat page):

1. An inner `li` dragged after a paragraph becomes `<p>`, and the toast says so. Undo gives the exact bytes.
2. "Liens utiles" dragged into a list becomes `<li>`. Undo.
3. A stat row over a paragraph: red, "stays in its list", nothing written.
4. The "56" row dragged over the "5" row's `dd` lands before that row. Undo.
5. Escape: no change.
6. h1 classes: the chips match the file; removing `mt-4` and adding `text-center sk-test` previews live; Apply
   changes only that attribute. Undo.
7. A quote in a class is refused; Cancel undoes the preview.
