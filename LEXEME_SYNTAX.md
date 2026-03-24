# Lexeme Syntax for QuickStatements V1

This documents the V1 (tab-separated) commands for working with Wikibase Lexemes,
Forms, and Senses. These extend the existing QuickStatements syntax — all the
regular item/property commands continue to work exactly as before.

Columns are separated by **tabs** (or `|` if there are no tabs in the input, as
with existing V1 syntax). The `||` separator for multiple commands also still works.

## How LAST, LAST_FORM, and LAST_SENSE work

With items, `CREATE` sets `LAST` to the newly created Q-ID. The same principle
applies to lexemes: `CREATE_LEXEME` sets `LAST` to the new L-ID.

**`ADD_FORM` and `ADD_SENSE` do not change `LAST`** — it keeps pointing at
the lexeme. Instead they set two separate keywords:

- **`LAST_FORM`** — set by `ADD_FORM`, points at the most recently created form
- **`LAST_SENSE`** — set by `ADD_SENSE`, points at the most recently created sense

This lets you build up a complete lexeme in one batch, editing both the lexeme
and its sub-entities:

```
CREATE_LEXEME	Q7725	Q1084	en:"water"
LAST	P12846	"w/water/"
LAST	ADD_FORM	en:"water"	Q110786
LAST_FORM	Rep_fr	"eau"
LAST_FORM	P31	Q5
LAST	ADD_FORM	en:"waters"	Q146786
LAST_FORM	GRAMMATICAL_FEATURE	Q146786
LAST	ADD_SENSE	en:"transparent liquid that forms rivers and rain"
LAST_SENSE	Gloss_fr	"liquide transparent qui forme les rivières et la pluie"
LAST_SENSE	P5137	Q202368
LAST	P5137	Q3024658	S248	Q328
```

Here `LAST` always refers to the lexeme, `LAST_FORM` refers to whichever form
was most recently created by `ADD_FORM`, and `LAST_SENSE` to the most recent
sense from `ADD_SENSE`. The final statement (P5137) targets the lexeme because
it uses `LAST`.

Each new `ADD_FORM` overwrites `LAST_FORM`, and each new `ADD_SENSE` overwrites
`LAST_SENSE`. If you need to refer to an older form or sense, use its full ID
(e.g. `L1560547-F1`).

When a new `CREATE` or `CREATE_LEXEME` is executed, both `LAST_FORM` and
`LAST_SENSE` are cleared.

## Creating Lexemes

```
CREATE_LEXEME	Q language	Q lexical-category	lang:"lemma"
```

You must provide at least one lemma. Multiple lemmas in different languages are
supported by adding more columns:

```
CREATE_LEXEME	Q7725	Q1084	en:"water"	fr:"eau"
```

This creates a lexeme with language Q7725 (English), lexical category Q1084 (noun),
and lemmas in English and French.

After creation the new lexeme ID is available as `LAST`, just like with `CREATE`.

## Creating Forms

```
L123	ADD_FORM	lang:"representation"	Q1,Q2
```

The grammatical features column (comma-separated Q-IDs) is optional. You can also
provide multiple representations in different languages:

```
L123	ADD_FORM	en:"color"	en-gb:"colour"	Q2,Q3
```

Grammatical feature items and representations can be mixed in any order —
the parser distinguishes them by format (`Q...` vs `lang:"text"`).

`ADD_FORM` does **not** change `LAST` — it stays on the lexeme. Instead it
sets `LAST_FORM` to the newly created form ID:

```
CREATE_LEXEME	Q7725	Q1084	en:"water"
LAST	ADD_FORM	en:"water"	Q110786
LAST_FORM	Rep_fr	"eau"
LAST	ADD_FORM	en:"waters"	Q146786
LAST_FORM	GRAMMATICAL_FEATURE	Q146786
```

After each `ADD_FORM`, `LAST_FORM` points at the form that was just created.
`LAST` still points at the lexeme throughout.

## Creating Senses

```
L123	ADD_SENSE	lang:"gloss text"
```

Multiple glosses in different languages:

```
L123	ADD_SENSE	en:"transparent liquid"	fr:"liquide transparent"
```

Like `ADD_FORM`, `ADD_SENSE` does **not** change `LAST`. Instead it sets
`LAST_SENSE`:

```
CREATE_LEXEME	Q7725	Q1084	en:"water"
LAST	ADD_SENSE	en:"transparent liquid that forms rivers and rain"
LAST_SENSE	Gloss_fr	"liquide transparent"
LAST_SENSE	P5137	Q202368
LAST	ADD_SENSE	en:"the sea"
LAST_SENSE	Gloss_fr	"la mer"
LAST	P31	Q5		/* still targets the lexeme */
```

## Editing Lemmas

To set or change a lemma on an existing lexeme:

```
L123	Lemma_en	"water"
```

The part after `Lemma_` is the language code. The prefix is case-insensitive,
but the language code itself is lowercased.

```
LAST	Lemma_fr	"eau"
```

## Setting Lexical Category

```
L123	LEXICAL_CATEGORY	Q1084
```

Replaces the lexeme's lexical category with the given item.

## Setting Language

```
L123	LANGUAGE	Q7725
```

Replaces the lexeme's language item.

## Editing Form Representations

To set or change a representation on a form:

```
L123-F1	Rep_en	"running"
```

The part after `Rep_` is the language code.

## Setting Grammatical Features

To replace the grammatical features on a form:

```
L123-F1	GRAMMATICAL_FEATURE	Q1,Q2,Q3
```

This is a comma-separated list of Q-IDs. It replaces all existing grammatical
features on the form.

## Editing Sense Glosses

To set or change a gloss on a sense:

```
L123-S1	Gloss_en	"act of running"
```

The part after `Gloss_` is the language code.

## Statements on Lexemes, Forms, and Senses

Adding statements to lexemes, forms, and senses uses the same syntax as for
items — this was already supported before these additions:

```
L123	P31	Q5
L123-F1	P31	Q5
L123-S1	P31	Q5
```

Qualifiers and sources work the same way as on item statements.

## Comments

As with all V1 commands, you can add a comment at the end of any line:

```
CREATE_LEXEME	Q7725	Q1084	en:"water" /* importing English nouns */
L123	Lemma_de	"Wasser" /* adding German lemma */
```

## Full Example

A realistic batch that creates a Breton proper noun with forms, senses,
and statements spread across all three levels:

```
CREATE_LEXEME	Q12107	Q147276	br:"Montroulez"
LAST	P12846	"m/montroulez/"
LAST	ADD_FORM	br:"Montroulez"	Q110786
LAST_FORM	Rep_fr	"Morlaix"
LAST_FORM	P31	Q5
LAST	ADD_SENSE	fr:"commune française"
LAST_SENSE	Gloss_en	"commune in Brittany, France"
LAST_SENSE	P5137	Q202368
LAST	P5137	Q202368
```

Line by line:
1. Create a Breton (Q12107) proper noun (Q147276) lexeme with lemma "Montroulez"
2. Add a pronunciation (P12846) statement to the lexeme
3. Add a singular form — `LAST_FORM` now points at this form
4. Add a French representation to that form via `LAST_FORM`
5. Add a statement to the form via `LAST_FORM`
6. Add a sense with a French gloss — `LAST_SENSE` now points at this sense
7. Add an English gloss to that sense via `LAST_SENSE`
8. Add a statement to the sense via `LAST_SENSE`
9. Add a statement to the lexeme via `LAST`

## Summary of LAST behavior

| Command          | Changes LAST? | Changes LAST_FORM? | Changes LAST_SENSE? |
|------------------|---------------|---------------------|----------------------|
| `CREATE`         | yes → Q-ID    | cleared             | cleared              |
| `CREATE_LEXEME`  | yes → L-ID    | cleared             | cleared              |
| `ADD_FORM`       | **no**        | yes → new F-ID      | no                   |
| `ADD_SENSE`      | **no**        | no                  | yes → new S-ID       |
| `Lemma_xx`       | no            | no                  | no                   |
| `LEXICAL_CATEGORY` | no          | no                  | no                   |
| `LANGUAGE`       | no            | no                  | no                   |
| `Rep_xx`         | no            | no                  | no                   |
| `Gloss_xx`       | no            | no                  | no                   |