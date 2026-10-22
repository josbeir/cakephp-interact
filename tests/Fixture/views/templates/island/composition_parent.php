<?php
declare(strict_types=1);

/**
 * @var \Interact\View\TemplateView $this
 * @var string $scope
 * @var string $loading
 */
echo $this->Interact->render('CompositionProbe', props: ['scope' => $scope], key: 'probe', loading: $loading, model: [
    'value' => 'data.value',
    'selected' => 'data.selected',
    'flag' => 'data.flag',
    'count' => 'data.count',
    'items' => 'data.items',
]);
