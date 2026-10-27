<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Lookup;

enum MatchKind: string
{
    case Exact = 'exact';
    case FuzzyStreet = 'fuzzy_street';
    case StreetCentre = 'street_centre';
    case Ambiguous = 'ambiguous';
    case NoMatch = 'no_match';
}
