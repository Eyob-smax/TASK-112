<?php

use App\Models\Document;
use App\Models\User;
use App\Policies\DocumentPolicy;

/**
 * Unit tests for DocumentPolicy.
 *
 * Exercises every gate method (before, viewAny, view, create, update,
 * archive, delete) with mocked User and Document models — no database required.
 */
describe('DocumentPolicy', function () {

    beforeEach(function () {
        $this->policy = new DocumentPolicy();
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

        expect($this->policy->before($user, 'view'))->toBeTrue();
        expect($this->policy->before($user, 'create'))->toBeTrue();
        expect($this->policy->before($user, 'delete'))->toBeTrue();
    });

    it('before() returns null for non-admin, allowing further policy evaluation', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasRole')->with('admin')->andReturn(false);

        expect($this->policy->before($user, 'view'))->toBeNull();
    });

    // -------------------------------------------------------------------------
    // viewAny()
    // -------------------------------------------------------------------------

    it('viewAny() returns true when user has view documents permission', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('can')->with('view documents')->andReturn(true);

        expect($this->policy->viewAny($user))->toBeTrue();
    });

    it('viewAny() returns false when user lacks view documents permission', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('can')->with('view documents')->andReturn(false);

        expect($this->policy->viewAny($user))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // view()
    // -------------------------------------------------------------------------

    it('view() returns true when user has permission and is in the same department', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 10]);
        $user->shouldReceive('can')->with('view documents')->andReturn(true);
        $user->shouldReceive('hasRole')->with(['admin', 'manager', 'auditor'])->andReturn(false);

        $doc = Mockery::mock(Document::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 10]);

        expect($this->policy->view($user, $doc))->toBeTrue();
    });

    it('view() returns false when user is in a different department with no cross-scope role', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 10]);
        $user->shouldReceive('can')->with('view documents')->andReturn(true);
        $user->shouldReceive('hasRole')->with(['admin', 'manager', 'auditor'])->andReturn(false);

        $doc = Mockery::mock(Document::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 99]);

        expect($this->policy->view($user, $doc))->toBeFalse();
    });

    it('view() returns true for manager role viewing a cross-department document', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 10]);
        $user->shouldReceive('can')->with('view documents')->andReturn(true);
        $user->shouldReceive('hasRole')->with(['admin', 'manager', 'auditor'])->andReturn(true);

        $doc = Mockery::mock(Document::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 99]);

        expect($this->policy->view($user, $doc))->toBeTrue();
    });

    it('view() returns true for auditor role viewing a cross-department document', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 1]);
        $user->shouldReceive('can')->with('view documents')->andReturn(true);
        $user->shouldReceive('hasRole')->with(['admin', 'manager', 'auditor'])->andReturn(true);

        $doc = Mockery::mock(Document::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 55]);

        expect($this->policy->view($user, $doc))->toBeTrue();
    });

    it('view() returns false when user lacks view permission even in the same department', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 10]);
        $user->shouldReceive('can')->with('view documents')->andReturn(false);

        $doc = Mockery::mock(Document::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 10]);

        expect($this->policy->view($user, $doc))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // create()
    // -------------------------------------------------------------------------

    it('create() returns true when user has create documents permission', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('can')->with('create documents')->andReturn(true);

        expect($this->policy->create($user))->toBeTrue();
    });

    it('create() returns false when user lacks create documents permission', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('can')->with('create documents')->andReturn(false);

        expect($this->policy->create($user))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // update()
    // -------------------------------------------------------------------------

    it('update() returns true when user has update permission and is in same department', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 5]);
        $user->shouldReceive('can')->with('update documents')->andReturn(true);
        $user->shouldReceive('hasRole')->with(['admin', 'manager', 'auditor'])->andReturn(false);

        $doc = Mockery::mock(Document::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 5]);

        expect($this->policy->update($user, $doc))->toBeTrue();
    });

    it('update() returns false when user lacks update permission', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 5]);
        $user->shouldReceive('can')->with('update documents')->andReturn(false);

        $doc = Mockery::mock(Document::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 5]);

        expect($this->policy->update($user, $doc))->toBeFalse();
    });

    it('update() returns false when user is in a different department with no cross-scope role', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 5]);
        $user->shouldReceive('can')->with('update documents')->andReturn(true);
        $user->shouldReceive('hasRole')->with(['admin', 'manager', 'auditor'])->andReturn(false);

        $doc = Mockery::mock(Document::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 99]);

        expect($this->policy->update($user, $doc))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // archive()
    // -------------------------------------------------------------------------

    it('archive() returns true when user has archive permission and is in same department', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 7]);
        $user->shouldReceive('can')->with('archive documents')->andReturn(true);
        $user->shouldReceive('hasRole')->with(['admin', 'manager', 'auditor'])->andReturn(false);

        $doc = Mockery::mock(Document::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 7]);

        expect($this->policy->archive($user, $doc))->toBeTrue();
    });

    it('archive() returns false when user lacks archive permission', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 7]);
        $user->shouldReceive('can')->with('archive documents')->andReturn(false);

        $doc = Mockery::mock(Document::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 7]);

        expect($this->policy->archive($user, $doc))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // delete() — same permission tier as archive
    // -------------------------------------------------------------------------

    it('delete() returns true when user has archive documents permission and is in same department', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 3]);
        $user->shouldReceive('can')->with('archive documents')->andReturn(true);
        $user->shouldReceive('hasRole')->with(['admin', 'manager', 'auditor'])->andReturn(false);

        $doc = Mockery::mock(Document::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 3]);

        expect($this->policy->delete($user, $doc))->toBeTrue();
    });

    it('delete() returns false when user lacks archive documents permission', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->setRawAttributes(['department_id' => 3]);
        $user->shouldReceive('can')->with('archive documents')->andReturn(false);

        $doc = Mockery::mock(Document::class)->makePartial();
        $doc->setRawAttributes(['department_id' => 3]);

        expect($this->policy->delete($user, $doc))->toBeFalse();
    });
});
