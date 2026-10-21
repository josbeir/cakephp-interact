<?php
declare(strict_types=1);

/**
 * templates/element/Interact/sales_chart.php
 *
 * @var \Interact\View\TemplateView $this
 */
?>
<div x-data="salesChart">
    <div x-interact:ignore>
        <canvas x-ref="canvas" role="img" aria-label="Daily orders"></canvas>
    </div>
    <button type="button" @click="$server.call('refreshChart')">Refresh chart</button>
</div>
