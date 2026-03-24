# Lexeme Syntax for QuickStatements V1

This documents the V1 (tab-separated) commands for working with Wikibase Lexemes,
Forms, and Senses. These extend the existing QuickStatements syntax — all the
regular item/property commands continue to work exactly as before.

Columns are separated by **tabs** (or `|` if there are no tabs in the input, as
with existing V1 syntax). The `||` separator for multiple commands also still works.

## How LAST works with lexemes

With items, `CREATE` sets `LAST` to the newly created Q-ID. The same principle
applies to lexemes: `CREATE_LEXEME` sets `LAST` to the new L-ID.

The important thing to know is that **`ADD_FORM` and `ADD_SENSE` do not change
`LAST`**. After adding a form or sense, `LAST` still points at the lexeme.
This lets you build up a complete lexeme in one go:

```
CREATE_LEXEME	Q7725	Q1084	en:"water"
LAST	P12846	"w/water/"
LAST	ADD_FORM	en:"water"	Q110786
LAST	ADD_FORM	en:"waters"	Q146786
LAST	ADD_SENSE	en:"transparent liquid that forms rivers and rain"
LAST	P5137	Q3024658	S248	Q328
```

Every `LAST` here refers to the lexeme. The forms and senses are attached to it,
and the statements (P12846, P5137) are added to the lexeme as well.

If you need to add statements to a specific form or sense, use its full ID
(e.g. `L1560547-F1`) instead of `LAST`.

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

`ADD_FORM` does **not** change `LAST` — it stays on the lexeme. This means
you can add several forms in a row without repeating the lexeme ID:

```
CREATE_LEXEME	Q7725	Q1084	en:"water"
LAST	ADD_FORM	en:"water"	Q110786
LAST	ADD_FORM	en:"waters"	Q146786
```

## Creating Senses

```
L123	ADD_SENSE	lang:"gloss text"
```

Multiple glosses in different languages:

```
L123	ADD_SENSE	en:"transparent liquid"	fr:"liquide transparent"
```

Like `ADD_FORM`, `ADD_SENSE` does **not** change `LAST`:

```
CREATE_LEXEME	Q7725	Q1084	en:"water"
LAST	ADD_SENSE	en:"transparent liquid that forms rivers and rain"
LAST	ADD_SENSE	en:"the sea"
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

A realistic batch that creates a Breton proper noun and populates it:

```
CREATE_LEXEME	Q12107	Q147276	br:"Montroulez"
LAST	P12846	"m/montroulez/"
LAST	ADD_FORM	br:"Montroulez"	Q110786
LAST	ADD_SENSE	fr:"commune française"
LAST	P5137	Q202368
```

Line by line:
1. Create a Breton (Q12107) proper noun (Q147276) lexeme with lemma "Montroulez"
2. Add a pronunciation (P12846) statement to the lexeme
3. Add a singular form — `LAST` still points at the lexeme
4. Add a sense with a French gloss — `LAST` still points at the lexeme
5. Add a "item for this sense" (P5137) statement — still targets the lexeme

## Summary of LAST behavior

| Command          | Changes LAST? | LAST after execution      |
|------------------|---------------|---------------------------|
| `CREATE`         | yes           | new Q-ID                  |
| `CREATE_LEXEME`  | yes           | new L-ID                  |
| `ADD_FORM`       | **no**        | stays on the lexeme       |
| `ADD_SENSE`      | **no**        | stays on the lexeme       |
| `Lemma_xx`       | no            | unchanged                 |
| `LEXICAL_CATEGORY` | no          | unchanged                 |
| `LANGUAGE`       | no            | unchanged                 |