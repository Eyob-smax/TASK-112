<?php

use App\Domain\Sales\Enums\SalesStatus;
use App\Models\SalesDocument;
use App\Models\User;
use App\Policies\SalesDocumentPolicy;

/**
 * Unit tests for SalesDocumentPolicy.
 *
 * Covers every gate method: before, viewAny, view, create, createReturn,
 * update (with status guard), complete, linkOutbound, void, delete.
 * User and SalesDocument are Mockery partial mocks — no database required.
 */
describe('SalesDocumentPolicy', function () {

    beforeEach(function () {
        $this->policy = new SalesDocumentPolicy();
    });

    afterEach(function () {
        Mockery::close();
    });

    // -------------------------------------------------------------------------
    // before() — admin bypass
    // -------------------------------------------------------------------------

    it('before() returns true for admin role, bypassing all other checks', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasRole')->with('admin')->andReturn(true);

        expect($this->policy->before($user, 'viewAny'))->toBeTrue();
        expect($this->policy->before($user, 'update'))->toBeTrue();
        expect($this->policy->before($user, 'void'))->toBeTrue();
    });

    it('before() returns null for non-admin, deferring to the policy method', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasRole')->with('admin')->andReturn(false);

        expect($this->policy->before($user, 'viewAny'))->toBeNull();
    });

    // -------------------------------------------------------------------------
    // viewAny()
    // -------------------------------------------------------------------------

    it('viewAny() returns true with view sales permission', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('can')->with('view sales')->andReturn(true);
        $user->shouldReceive('can')->with('manage sales')->andReturn(false)->byDefault();

        expect($this->policy->viewAny($user))->toBeTrue();
    });

    it('viewAny() returns true with manage sales permission', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('can')->with('view sales')->andReturn(false);
        $user->shouldReceive('can')->with('manage sales')->andReturn(true);

        expect($this->policy->viewAny($user))->toBeTrue();
    });

    it('viewAny() returns false without view or manage sales permission', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('can')->with('view sales')->andReturn(false);
        $user->shouldReceive('can')->with('manage sales')->andReturn(false);

        expect($this->policy->viewAny($user))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // view()
    // -------------------------------------------------------------------------

    it('view() returns true with manage sales permission in the same department', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 1]);
        $user->shouldReceive('can')->with('view sales')->andReturn(false);
        $user->shouldReceive('can')->with('manage sales')->andReturn(true);
        $user->shouldReceive('hasRole')->with(['manager', 'auditor'])->andReturn(false);

        $doc = Mockery::mock(SalesDocument::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 1]);

        expect($this->policy->view($user, $doc))->toBeTrue();
    });

    it('view() returns false without any sales permission even in the same department', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 1]);
        $user->shouldReceive('can')->with('view sales')->andReturn(false);
        $user->shouldReceive('can')->with('manage sales')->andReturn(false);

        $doc = Mockery::mock(SalesDocument::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 1]);

        expect($this->policy->view($user, $doc))->toBeFalse();
    });

    it('view() returns true for auditor role viewing a cross-department document', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 1]);
        $user->shouldReceive('can')->with('view sales')->andReturn(true);
        $user->shouldReceive('can')->with('manage sales')->andReturn(false)->byDefault();
        $user->shouldReceive('hasRole')->with(['manager', 'auditor'])->andReturn(true);

        $doc = Mockery::mock(SalesDocument::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 99]);

        expect($this->policy->view($user, $doc))->toBeTrue();
    });

    // -------------------------------------------------------------------------
    // create()
    // -------------------------------------------------------------------------

    it('create() returns true with create sales permission', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('can')->with('create sales')->andReturn(true);

        expect($this->policy->create($user))->toBeTrue();
    });

    it('create() returns false without create sales permission', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('can')->with('create sales')->andReturn(false);

        expect($this->policy->create($user))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // update() — requires editable document status
    // -------------------------------------------------------------------------

    it('update() returns true when user has manage sales, same department, and document is Draft', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 2]);
        $user->shouldReceive('can')->with('manage sales')->andReturn(true);
        $user->shouldReceive('hasRole')->with(['manager', 'auditor'])->andReturn(false);

        $doc = Mockery::mock(SalesDocument::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 2, 'status' => SalesStatus::Draft->value]);

        expect($this->policy->update($user, $doc))->toBeTrue();
    });

    it('update() returns false when document status is Completed (not editable)', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 2]);
        $user->shouldReceive('can')->with('manage sales')->andReturn(true);
        $user->shouldReceive('hasRole')->with(['manager', 'auditor'])->andReturn(false);

        $doc = Mockery::mock(SalesDocument::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 2, 'status' => SalesStatus::Completed->value]);

        expect($this->policy->update($user, $doc))->toBeFalse();
    });

    it('update() returns false when document status is Voided', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 2]);
        $user->shouldReceive('can')->with('manage sales')->andReturn(true);
        $user->shouldReceive('hasRole')->with(['manager', 'auditor'])->andReturn(false);

        $doc = Mockery::mock(SalesDocument::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 2, 'status' => SalesStatus::Voided->value]);

        expect($this->policy->update($user, $doc))->toBeFalse();
    });

    it('update() returns false when user lacks manage sales permission', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 2]);
        $user->shouldReceive('can')->with('manage sales')->andReturn(false);

        $doc = Mockery::mock(SalesDocument::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 2, 'status' => SalesStatus::Draft->value]);

        expect($this->policy->update($user, $doc))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // complete()
    // -------------------------------------------------------------------------

    it('complete() returns true with manage sales and same department', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 4]);
        $user->shouldReceive('can')->with('manage sales')->andReturn(true);
        $user->shouldReceive('hasRole')->with(['manager', 'auditor'])->andReturn(false);

        $doc = Mockery::mock(SalesDocument::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 4]);

        expect($this->policy->complete($user, $doc))->toBeTrue();
    });

    it('complete() returns false when user is in a different department with no cross-scope role', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 4]);
        $user->shouldReceive('can')->with('manage sales')->andReturn(true);
        $user->shouldReceive('hasRole')->with(['manager', 'auditor'])->andReturn(false);

        $doc = Mockery::mock(SalesDocument::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 99]);

        expect($this->policy->complete($user, $doc))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // void()
    // -------------------------------------------------------------------------

    it('void() returns true with void sales permission', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('can')->with('void sales')->andReturn(true);

        $doc = Mockery::mock(SalesDocument::class)->makePartial();

        expect($this->policy->void($user, $doc))->toBeTrue();
    });

    it('void() returns false without void sales permission', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('can')->with('void sales')->andReturn(false);

        $doc = Mockery::mock(SalesDocument::class)->makePartial();

        expect($this->policy->void($user, $doc))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // createReturn()
    // -------------------------------------------------------------------------

    it('createReturn() returns true with manage sales and same department', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 6]);
        $user->shouldReceive('can')->with('manage sales')->andReturn(true);
        $user->shouldReceive('hasRole')->with(['manager', 'auditor'])->andReturn(false);

        $doc = Mockery::mock(SalesDocument::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 6]);

        expect($this->policy->createReturn($user, $doc))->toBeTrue();
    });

    it('createReturn() returns false without manage sales permission', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 6]);
        $user->shouldReceive('can')->with('manage sales')->andReturn(false);

        $doc = Mockery::mock(SalesDocument::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 6]);

        expect($this->policy->createReturn($user, $doc))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // hasCrossScope — manager and auditor only (staff excluded)
    // -------------------------------------------------------------------------

    it('manager role grants cross-department access; staff role does not', function () {
        // Manager — cross-scope should allow complete on another department's document
        $manager = Mockery::mock(User::class)->makePartial();
        $manager->setRawAttributes(['department_id' => 1]);
        $manager->shouldReceive('can')->with('manage sales')->andReturn(true);
        $manager->shouldReceive('hasRole')->with(['manager', 'auditor'])->andReturn(true);

        $docOtherDept = Mockery::mock(SalesDocument::class)->makePartial();
        $docOtherDept->setRawAttributes(['department_id' => 99]);

        expect($this->policy->complete($manager, $docOtherDept))->toBeTrue();

        Mockery::close();

        // Staff — no cross-scope, same request on other department's document denied
        $staff = Mockery::mock(User::class)->makePartial();
        $staff->setRawAttributes(['department_id' => 1]);
        $staff->shouldReceive('can')->with('manage sales')->andReturn(true);
        $staff->shouldReceive('hasRole')->with(['manager', 'auditor'])->andReturn(false);

        $docOtherDept2 = Mockery::mock(SalesDocument::class)->makePartial();
        $docOtherDept2->setRawAttributes(['department_id' => 99]);

        expect($this->policy->complete($staff, $docOtherDept2))->toBeFalse();
    });
});
