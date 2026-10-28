<?php
declare(strict_types=1);

namespace TestApp\Island\Article;

/**
 * Exercises background parent renders while retaining independently owned child editor drafts.
 *
 * The application's existing list finders and child keys remain authoritative.
 */
class PollingListIsland extends ListingIsland {}
