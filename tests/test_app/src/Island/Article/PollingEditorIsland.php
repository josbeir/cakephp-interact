<?php
declare(strict_types=1);

namespace TestApp\Island\Article;

/**
 * Exercises polling on a real entity form without duplicating its persistence or authorization.
 *
 * The dedicated acceptance page keeps these timers separate from the ordinary editing demo.
 */
class PollingEditorIsland extends EditorIsland {}
