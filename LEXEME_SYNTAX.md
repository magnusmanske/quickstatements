# Lexeme Syntax for QuickStatements V1

This documents the V1 (tab-separated) commands for working with Wikibase Lexemes,
Forms, and Senses. These extend the existing QuickStatements syntax — all the
regular item/property commands continue to work exactly as before.

Columns are separated by **tabs** (or `|` if there are no tabs in the input, as
with existing V1 syntax). The `||` separator for multiple commands also still works.

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

After creation, the new lexeme ID is available as `LAST`, just like with `CREATE`.

## Creating Forms

```
L123	ADD_FORM	lang:"representation"	Q1,Q2
```

The grammatical features column (comma-separated Q-IDs) is optional. You can also
provide multiple representations in different languages:

```
L123	ADD_FORM	en:"color"	en-gb:"colour"	Q2	Q3
```

Grammatical feature items and representations can be mixed in any order —
the parser distinguishes them by format (`Q...` vs `lang:"text"`).

With `LAST` after a `CREATE_LEXEME`:

```
CREATE_LEXEME	Q7725	Q1084	en:"water"
LAST	ADD_FORM	en:"waters"	Q146786
```

The created form ID becomes the new `LAST` value.

## Creating Senses

```
L123	ADD_SENSE	lang:"gloss text"
```

Multiple glosses in different languages:

```
L123	ADD_SENSE	en:"transparent liquid"	fr:"liquide transparent"
```

Works with `LAST`:

```
CREATE_LEXEME	Q7725	Q1084	en:"water"
LAST	ADD_SENSE	en:"transparent liquid that forms rivers and rain"
```

The created sense ID becomes the new `LAST` value.

## Editing Lemmas

To set or change a lemma on an existing lexeme:

```
L123	Lemma_en	"water"
```

The part after `Lemma_` is the language code. Case-insensitive prefix, but the
language code itself is lowercased.

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

A realistic batch that creates a lexeme with all the trimmings:

```
CREATE_LEXEME	Q7725	Q1084	en:"water"
LAST	Lemma_fr	"eau"
LAST	ADD_FORM	en:"water"	Q110786
LAST	ADD_FORM	en:"waters"	Q146786
LAST	ADD_SENSE	en:"transparent liquid that forms rivers and rain"
LAST	P5137	Q3024658	S248	Q328
```

Line by line:
1. Create an English noun lexeme with lemma "water"
2. Add a French lemma to it
3. Add a singular form
4. Add a plural form
5. Add a sense with an English gloss
6. Add a statement (on the lexeme) with a reference
