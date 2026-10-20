<?php

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\InvalidTransition;

it('only allows documented transitions', function () {
    expect(OrderStatus::Open->canTransitionTo(OrderStatus::Paid))->toBeTrue()
        ->and(OrderStatus::Paid->canTransitionTo(OrderStatus::Open))->toBeFalse()
        ->and(OrderStatus::Paid->canTransitionTo(OrderStatus::Cancelled))->toBeFalse()
        ->and(OrderStatus::Cancelled->allowedNext())->toBe([])
        ->and(OrderStatus::Paid->canTransitionTo(OrderStatus::Refunded))->toBeTrue();
});

it('throws on an illegal transition', function () {
    OrderStatus::Paid->transitionTo(OrderStatus::Open);
})->throws(InvalidTransition::class);

it('locks paid orders from editing', function () {
    expect(OrderStatus::Open->isEditable())->toBeTrue()->and(OrderStatus::Paid->isEditable())->toBeFalse();
});
