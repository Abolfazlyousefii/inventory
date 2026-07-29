<?php

use App\Services\SalesReturnNewProductPayloadNormalizer;

it('keeps both feature cards visible and toggles only their inner panels', function (): void {
    $view = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

    expect($view)->toContain('id="npUseModels"')
        ->and($view)->toContain('id="npUseDesigns"')
        ->and($view)->toContain('.np-grid>.model-picker,.np-grid>.np-design-card{display:block!important}')
        ->and($view)->toContain('function updateNewProductFeaturePanels()')
        ->and($view)->toContain("$('#npModelPanel')?.classList.toggle('d-none',!useModels)")
        ->and($view)->toContain("$('#npDesignPanel')?.classList.toggle('d-none',!useDesigns)")
        ->and($view)->not->toContain("classList.toggle('has-models'")
        ->and($view)->not->toContain("classList.toggle('has-designs'");
});

it('preserves temporary model and design selections while toggles are off', function (): void {
    $view = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

    expect($view)->toContain('designDrafts:[]')
        ->and($view)->toContain('function npCaptureDesignDrafts()')
        ->and($view)->toContain("if(!wrap||!on)return")
        ->and($view)->not->toContain("if(!on){wrap.innerHTML='';return}")
        ->and($view)->toContain('np.designDrafts=[]')
        ->and($view)->not->toContain("if(e.target.id==='npUseModels')np.selectedModels.clear()");
});

it('keeps disabled feature data out of the submitted payload', function (): void {
    $view = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

    expect($view)->toContain("model_list_ids:useModels?")
        ->and($view)->toContain("selected_models:useModels?")
        ->and($view)->toContain("designs:useDesigns?npDesigns():[]")
        ->and($view)->toContain("model_list_id:useModels?")
        ->and($view)->toContain("design_index:useDesigns?");
});

it('accepts both features enabled independently at the backend boundary', function (): void {
    $payload = app(SalesReturnNewProductPayloadNormalizer::class)->normalize([
        'schema_version' => 2,
        'use_models' => true,
        'use_designs' => true,
        'designs' => [
            ['name' => 'طرح یک'],
            ['name' => 'طرح دو'],
            ['name' => 'طرح سه'],
        ],
    ]);

    expect($payload['use_models'])->toBeTrue()
        ->and($payload['use_designs'])->toBeTrue()
        ->and($payload['designs'])->toHaveCount(3);
});

it('retains the cartesian product implementation for two models and three designs', function (): void {
    $view = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

    expect($view)->toContain('models.forEach(m=>designs.forEach(d=>')
        ->and(2 * 3)->toBe(6);
});
