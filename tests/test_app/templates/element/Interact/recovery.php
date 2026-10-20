<?php
declare(strict_types=1);
use Cake\Routing\Router;
use function Cake\Core\h;

/**
 * @var string $message
 * @var int $saves
 */
?>
<section class="demo-card" aria-label="Failure recovery">
    <div class="card-heading"><div><p class="eyebrow">04 / Explicit recovery</p><h2>Keep the draft. Understand the failure.</h2></div></div>
    <p>Simulate a failure, keep typing, then choose what to do. These examples do not save to a database.</p>
    <form data-interact-submit="save">
        <label>Recovery draft<textarea data-interact-model="message" data-interact-update="input"><?= (string)h($message) ?></textarea></label>
        <button type="submit" :disabled="$server.busy">Accept draft</button>
        <p role="status">Accepted <?= (string)$saves ?> times.</p>
    </form>
    <div class="button-row">
        <button type="button" @click="$server.call('simulate', 'server')" :disabled="$server.busy">Simulate server failure</button>
        <button type="button" @click="$server.call('simulate', 'expired')" :disabled="$server.busy">Simulate expiry</button>
    </div>
    <div x-show="$server.paused" x-cloak>
        <p class="request-error" role="alert" x-text="$server.error ? $server.error.message : ''"></p>
        <p>Automatic updates are paused. A failed operation may already have completed: check its result before submitting again.</p>
        <label>Draft export<textarea readonly :value="$server.exportDraft()"></textarea></label>
        <p>Select and copy the draft before leaving. Reloading does not restore it automatically.</p>
        <button type="button" @click="$server.sync()" x-show="$server.error && $server.error.recovery === 'retry'" :disabled="$server.busy">Synchronize latest draft</button>
        <a href="<?= (string)h(Router::url(['_name' => 'demo:playground'])) ?>">Reload page</a>
    </div>
</section>
