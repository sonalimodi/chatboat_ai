<?php

namespace App\Services\Rag\Structure;

/**
 * Repairs a common PDF extraction defect: the "Th" ligature glyph has no
 * Unicode mapping in some fonts, so it vanishes from the text - "The
 * conflict" comes out as "e conflict", "Thailand" as "ailand". That
 * breaks both keyword search (no "the"/"these" is fine, but "Thailand"
 * becoming "ailand" is not) and the LLM's reading of the passage.
 *
 * Only words that are unambiguous once "Th" is restored are touched, and
 * sentence-initial words only where they really start a sentence (after
 * terminal punctuation, or at the start of a line that follows one), so a
 * wrapped continuation like "...which\nis expected" is left alone.
 */
class GlyphRepair
{
    /**
     * Damaged sentence-initial word => repaired word.
     */
    const SENTENCE_START = [
        'e' => 'The', 'ese' => 'These', 'is' => 'This', 'at' => 'That', 'ey' => 'They',
        'ere' => 'There', 'en' => 'Then', 'us' => 'Thus', 'ird' => 'Third', 'irdly' => 'Thirdly',
        'ree' => 'Three', 'ose' => 'Those', 'ough' => 'Though', 'erefore' => 'Therefore',
        'eir' => 'Their', 'em' => 'Them', 'ereby' => 'Thereby', 'roughout' => 'Throughout',
        'rough' => 'Through', 'ereafter' => 'Thereafter', 'ird-' => 'Third-',
    ];

    /**
     * Damaged words that are unambiguous anywhere. The same fonts can
     * also drop the "fi" ligature ("nancial", "speci c").
     */
    const ANYWHERE = [
        'ailand' => 'Thailand',
        'nancial' => 'financial',
        'nancing' => 'financing',
        'speci c' => 'specific',
        'signi cant' => 'significant',
        'signi cantly' => 'significantly',
        'bene t' => 'benefit',
        'bene ts' => 'benefits',
        'de cit' => 'deficit',
        'de cits' => 'deficits',
    ];

    /**
     * Whether a document shows the defect at all. Requires several
     * hits so a single odd sentence never triggers repairs.
     *
     * @param  array<int, string>  $pages
     * @return bool
     */
    public function isNeeded(array $pages): bool
    {
        $hits = 0;
        foreach ($pages as $text) {
            $hits += preg_match_all('/(?:^|[.!?]\s+)(?:e|ese|is)\s+\p{Ll}/mu', $text);
        }

        return $hits >= 10;
    }

    /**
     * @param  string  $text
     * @return string
     */
    public function repair(string $text): string
    {
        $words = implode('|', array_map(function ($w) {
            return preg_quote($w, '/');
        }, array_keys(self::SENTENCE_START)));

        $lines = explode("\n", $text);
        $previous = '';

        foreach ($lines as $i => $line) {
            // Mid-line sentence starts: ". e conflict" -> ". The conflict".
            $line = preg_replace_callback(
                '/([.!?]["\x{201D}\x{2019})]?\s+)(' . $words . ')(?=[\s,])/u',
                function ($m) {
                    return $m[1] . self::SENTENCE_START[$m[2]];
                },
                $line
            );

            // Line-initial: only if the previous line ended a sentence or
            // was a short heading/caption/page-furniture line (which start
            // with a capital or digit - unlike a wrapped sentence fragment).
            $trimmedPrevious = trim($previous);
            $startsSentence = $trimmedPrevious === ''
                || preg_match('/[.!?:)"\x{201D}]$/u', $trimmedPrevious)
                || $this->isHeadingLike($trimmedPrevious);

            if ($startsSentence) {
                $line = preg_replace_callback(
                    '/^(\s*)(' . $words . ')(?=[\s,])/u',
                    function ($m) {
                        return $m[1] . self::SENTENCE_START[$m[2]];
                    },
                    $line
                );
            }

            foreach (self::ANYWHERE as $damaged => $fixed) {
                // Not preceded by a letter, so "financial" never becomes "fifinancial".
                $line = preg_replace('/(?<!\pL)' . preg_quote($damaged, '/') . '\b/u', $fixed, $line);
            }

            $lines[$i] = $line;
            if (trim($line) !== '') {
                $previous = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * A short heading/caption/page-furniture line ("Global trade",
     * "BOX 1.1 ...", "13") as opposed to a wrapped sentence fragment
     * ("Growth in the region, which"): starts with a capital or digit, has
     * no comma, and doesn't end on a connective word.
     *
     * @param  string  $line
     * @return bool
     */
    protected function isHeadingLike(string $line): bool
    {
        return mb_strlen($line) <= 80
            && preg_match('/^[\p{Lu}\d]/u', $line)
            && strpos($line, ',') === false
            && ! preg_match('/\b(which|that|and|or|the|of|to|in|a|an|for|with|by|as|is|are|was|were|from|on|at|its|their)$/iu', $line);
    }
}
