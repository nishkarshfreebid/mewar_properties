<?php

namespace Tests\Feature;

use App\Models\Enquiry;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisitManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    private function createUserWithRole(string $roleName): User
    {
        $user = User::factory()->create();

        $role = Role::where('name', $roleName)->firstOrFail();

        $user->roles()->attach($role->id);

        return $user;
    }

    private function createProperty(
        User $owner,
        ?User $agent = null,
        string $approvalStatus = 'approved',
        string $publicationStatus = 'published'
    ): Property {
        return Property::create([
            'owner_id' => $owner->id,
            'assigned_agent_id' => $agent?->id,
            'title' => 'Visit Test Property',
            'slug' => 'visit-test-' . uniqid(),
            'description' => 'Property for visit tests',
            'property_type' => 'apartment',
            'listing_type' => 'sale',
            'price' => 5000000,
            'bedrooms' => 2,
            'bathrooms' => 2,
            'area' => 1200,
            'area_unit' => 'sqft',
            'city' => 'Jaipur',
            'state' => 'Rajasthan',
            'country' => 'India',
            'approval_status' => $approvalStatus,
            'publication_status' => $publicationStatus,
            'approved_at' => $approvalStatus === 'approved' ? now() : null,
            'published_at' => $publicationStatus === 'published' ? now() : null,
        ]);
    }

    private function createEnquiry(
        User $customer,
        Property $property
    ): Enquiry {
        return Enquiry::create([
            'property_id' => $property->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '9876543210',
            'message' => 'Interested in this property.',
            'status' => 'new',
            'source' => 'website',
        ]);
    }

    private function createVisit(
        User $customer,
        Property $property,
        ?User $assignee = null,
        array $overrides = []
    ): Visit {
        return Visit::create(array_merge([
            'property_id' => $property->id,
            'customer_id' => $customer->id,
            'assigned_agent_id' => $assignee?->id,
            'scheduled_at' => now()->addDays(2),
            'duration_minutes' => 30,
            'status' => 'scheduled',
            'customer_notes' => 'Please contact before visiting.',
        ], $overrides));
    }

    public function test_customer_can_schedule_visit_for_published_property(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');
        $agent = $this->createUserWithRole('agent');

        $property = $this->createProperty($owner, $agent);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/visits', [
                'property_id' => $property->id,
                'scheduled_at' => now()->addDays(2)->toDateTimeString(),
                'duration_minutes' => 60,
                'customer_notes' => 'Please call before arriving.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.customer_id', $customer->id)
            ->assertJsonPath('data.property_id', $property->id)
            ->assertJsonPath('data.assigned_agent_id', $agent->id)
            ->assertJsonPath('data.status', 'scheduled');

        $this->assertDatabaseHas('visits', [
            'property_id' => $property->id,
            'customer_id' => $customer->id,
            'assigned_agent_id' => $agent->id,
            'duration_minutes' => 60,
            'status' => 'scheduled',
        ]);
    }

    public function test_visit_responses_return_only_selected_property_fields(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');
        $property = $this->createProperty($owner);

        // 1. Create visit and check response.
        $createResponse = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/visits', [
                'property_id' => $property->id,
                'scheduled_at' => now()->addDays(3)->toDateTimeString(),
            ])
            ->assertCreated();

        $visitId = $createResponse->json('data.id');

        $expectedPropertyFields = [
            'id',
            'title',
            'slug',
            'owner_id',
            'assigned_agent_id',
        ];

        $createResponse->assertJsonStructure([
            'data' => [
                'property' => $expectedPropertyFields,
            ],
        ]);

        $this->assertSame(
            $expectedPropertyFields,
            array_keys($createResponse->json('data.property'))
        );

        // 2. Check individual visit response.
        $showResponse = $this->getJson("/api/v1/visits/{$visitId}")
            ->assertOk();

        $this->assertSame(
            $expectedPropertyFields,
            array_keys($showResponse->json('data.property'))
        );

        // 3. Check visit listing response.
        $listResponse = $this->getJson('/api/v1/visits')
            ->assertOk();

        $this->assertSame(
            $expectedPropertyFields,
            array_keys($listResponse->json('data.data.0.property'))
        );
    }
    public function test_visit_mutation_responses_return_only_selected_property_fields(): void
    {
        $admin = $this->createUserWithRole('admin');
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');
        $agent = $this->createUserWithRole('agent');

        $property = $this->createProperty($owner);
        $visit = $this->createVisit($customer, $property);

        $expectedPropertyFields = [
            'id',
            'title',
            'slug',
            'owner_id',
            'assigned_agent_id',
        ];

        // Assignment response
        $assignResponse = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/visits/{$visit->id}/assign", [
                'assigned_user_id' => $agent->id,
            ])
            ->assertOk();

        $this->assertSame(
            $expectedPropertyFields,
            array_keys($assignResponse->json('data.property'))
        );

        // Reschedule response
        $rescheduleResponse = $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/v1/visits/{$visit->id}/reschedule", [
                'scheduled_at' => now()->addDays(5)->toDateTimeString(),
            ])
            ->assertOk();

        $this->assertSame(
            $expectedPropertyFields,
            array_keys($rescheduleResponse->json('data.property'))
        );

        // Status update response
        $statusResponse = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/visits/{$visit->id}/status", [
                'status' => 'confirmed',
            ])
            ->assertOk();

        $this->assertSame(
            $expectedPropertyFields,
            array_keys($statusResponse->json('data.property'))
        );
    }
    public function test_customer_cannot_schedule_visit_for_unpublished_property(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty(
            $owner,
            null,
            'approved',
            'draft'
        );

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/visits', [
                'property_id' => $property->id,
                'scheduled_at' => now()->addDays(2)->toDateTimeString(),
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('visits', [
            'property_id' => $property->id,
            'customer_id' => $customer->id,
        ]);
    }

    public function test_non_customer_cannot_schedule_visit(): void
    {
        $owner = $this->createUserWithRole('owner');

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/visits', [
                'property_id' => 1,
                'scheduled_at' => now()->addDays(2)->toDateTimeString(),
            ])
            ->assertForbidden();
    }

    public function test_visit_scheduling_requires_future_date(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/visits', [
                'property_id' => $property->id,
                'scheduled_at' => now()->subDay()->toDateTimeString(),
            ])
            ->assertUnprocessable();
    }

    public function test_visit_duration_must_be_between_15_and_240_minutes(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/visits', [
                'property_id' => $property->id,
                'scheduled_at' => now()->addDays(2)->toDateTimeString(),
                'duration_minutes' => 10,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('visits', [
            'property_id' => $property->id,
            'customer_id' => $customer->id,
        ]);
    }

    public function test_customer_can_link_own_enquiry_to_visit(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);
        $enquiry = $this->createEnquiry($customer, $property);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/visits', [
                'property_id' => $property->id,
                'enquiry_id' => $enquiry->id,
                'scheduled_at' => now()->addDays(2)->toDateTimeString(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.enquiry_id', $enquiry->id);
    }

    public function test_customer_cannot_link_another_customers_enquiry(): void
    {
        $customer = $this->createUserWithRole('customer');
        $anotherCustomer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);
        $enquiry = $this->createEnquiry($anotherCustomer, $property);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/visits', [
                'property_id' => $property->id,
                'enquiry_id' => $enquiry->id,
                'scheduled_at' => now()->addDays(2)->toDateTimeString(),
            ])
            ->assertNotFound();
    }

    public function test_customer_can_only_see_own_visits(): void
    {
        $customer = $this->createUserWithRole('customer');
        $anotherCustomer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);

        $ownVisit = $this->createVisit($customer, $property);
        $otherVisit = $this->createVisit($anotherCustomer, $property);

        $response = $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/visits')
            ->assertOk();

        $ids = collect($response->json('data.data'))
            ->pluck('id')
            ->all();

        $this->assertContains($ownVisit->id, $ids);
        $this->assertNotContains($otherVisit->id, $ids);
    }

    public function test_owner_can_only_see_visits_for_own_properties(): void
    {
        $owner = $this->createUserWithRole('owner');
        $anotherOwner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');

        $ownProperty = $this->createProperty($owner);
        $otherProperty = $this->createProperty($anotherOwner);

        $ownVisit = $this->createVisit($customer, $ownProperty);
        $otherVisit = $this->createVisit($customer, $otherProperty);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/visits')
            ->assertOk();

        $ids = collect($response->json('data.data'))
            ->pluck('id')
            ->all();

        $this->assertContains($ownVisit->id, $ids);
        $this->assertNotContains($otherVisit->id, $ids);
    }

    public function test_agent_can_only_see_directly_or_property_assigned_visits(): void
    {
        $agent = $this->createUserWithRole('agent');
        $anotherAgent = $this->createUserWithRole('agent');
        $owner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');

        $assignedProperty = $this->createProperty($owner, $agent);
        $otherProperty = $this->createProperty($owner);

        $propertyAssignedVisit = $this->createVisit(
            $customer,
            $assignedProperty
        );

        $directlyAssignedVisit = $this->createVisit(
            $customer,
            $otherProperty,
            $agent
        );

        $otherVisit = $this->createVisit(
            $customer,
            $otherProperty,
            $anotherAgent
        );

        $response = $this->actingAs($agent, 'sanctum')
            ->getJson('/api/v1/visits')
            ->assertOk();

        $ids = collect($response->json('data.data'))
            ->pluck('id')
            ->all();

        $this->assertContains($propertyAssignedVisit->id, $ids);
        $this->assertContains($directlyAssignedVisit->id, $ids);
        $this->assertNotContains($otherVisit->id, $ids);
    }

    public function test_staff_can_only_see_directly_assigned_visits(): void
    {
        $staff = $this->createUserWithRole('staff');
        $anotherStaff = $this->createUserWithRole('staff');
        $owner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');

        $property = $this->createProperty($owner);

        $assignedVisit = $this->createVisit(
            $customer,
            $property,
            $staff
        );

        $otherVisit = $this->createVisit(
            $customer,
            $property,
            $anotherStaff
        );

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/visits')
            ->assertOk();

        $ids = collect($response->json('data.data'))
            ->pluck('id')
            ->all();

        $this->assertContains($assignedVisit->id, $ids);
        $this->assertNotContains($otherVisit->id, $ids);
    }

    public function test_customer_cannot_view_another_customers_visit(): void
    {
        $customer = $this->createUserWithRole('customer');
        $anotherCustomer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);
        $visit = $this->createVisit($anotherCustomer, $property);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/visits/{$visit->id}")
            ->assertForbidden();
    }

    public function test_customer_can_view_own_visit(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);
        $visit = $this->createVisit($customer, $property);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/visits/{$visit->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $visit->id);
    }

    public function test_owner_can_view_visit_for_own_property(): void
    {
        $owner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');

        $property = $this->createProperty($owner);
        $visit = $this->createVisit($customer, $property);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/visits/{$visit->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $visit->id);
    }

    public function test_admin_can_confirm_visit(): void
    {
        $admin = $this->createUserWithRole('admin');
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);
        $visit = $this->createVisit($customer, $property);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/visits/{$visit->id}/status", [
                'status' => 'confirmed',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertNotNull($visit->fresh()->confirmed_at);
    }

    public function test_admin_can_complete_confirmed_visit(): void
    {
        $admin = $this->createUserWithRole('admin');
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);

        $visit = $this->createVisit($customer, $property, null, [
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/visits/{$visit->id}/status", [
                'status' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertNotNull($visit->fresh()->completed_at);
    }

    public function test_customer_can_cancel_own_visit(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);
        $visit = $this->createVisit($customer, $property);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/v1/visits/{$visit->id}/status", [
                'status' => 'cancelled',
                'cancellation_reason' => 'Unable to visit on the scheduled date.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertNotNull($visit->fresh()->cancelled_at);

        $this->assertDatabaseHas('visits', [
            'id' => $visit->id,
            'cancellation_reason' => 'Unable to visit on the scheduled date.',
        ]);
    }

    public function test_customer_cannot_change_visit_to_confirmed(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);
        $visit = $this->createVisit($customer, $property);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/v1/visits/{$visit->id}/status", [
                'status' => 'confirmed',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('visits', [
            'id' => $visit->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_completed_visit_cannot_be_changed_again(): void
    {
        $admin = $this->createUserWithRole('admin');
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);

        $visit = $this->createVisit($customer, $property, null, [
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/visits/{$visit->id}/status", [
                'status' => 'cancelled',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('visits', [
            'id' => $visit->id,
            'status' => 'completed',
        ]);
    }

    public function test_invalid_visit_status_is_rejected(): void
    {
        $admin = $this->createUserWithRole('admin');
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);
        $visit = $this->createVisit($customer, $property);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/visits/{$visit->id}/status", [
                'status' => 'invalid_status',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('visits', [
            'id' => $visit->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_agent_can_manage_directly_assigned_visit(): void
    {
        $agent = $this->createUserWithRole('agent');
        $owner = $this->createUserWithRole('owner');
        $customer = $this->createUserWithRole('customer');

        $property = $this->createProperty($owner);
        $visit = $this->createVisit($customer, $property, $agent);

        $this->actingAs($agent, 'sanctum')
            ->patchJson("/api/v1/visits/{$visit->id}/status", [
                'status' => 'confirmed',
                'internal_notes' => 'Agent confirmed the appointment.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseHas('visits', [
            'id' => $visit->id,
            'internal_notes' => 'Agent confirmed the appointment.',
        ]);
    }

    public function test_admin_can_assign_visit_to_agent(): void
    {
        $admin = $this->createUserWithRole('admin');
        $agent = $this->createUserWithRole('agent');
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);
        $visit = $this->createVisit($customer, $property);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/visits/{$visit->id}/assign", [
                'assigned_user_id' => $agent->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.assigned_agent.id', $agent->id);

        $this->assertDatabaseHas('visits', [
            'id' => $visit->id,
            'assigned_agent_id' => $agent->id,
        ]);

        $this->assertDatabaseHas('visit_activities', [
            'visit_id' => $visit->id,
            'user_id' => $admin->id,
            'activity_type' => 'assignment_changed',
        ]);
    }

    public function test_admin_can_assign_visit_to_staff(): void
    {
        $admin = $this->createUserWithRole('admin');
        $staff = $this->createUserWithRole('staff');
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);
        $visit = $this->createVisit($customer, $property);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/visits/{$visit->id}/assign", [
                'assigned_user_id' => $staff->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.assigned_agent.id', $staff->id);

        $this->assertDatabaseHas('visits', [
            'id' => $visit->id,
            'assigned_agent_id' => $staff->id,
        ]);
    }

    public function test_admin_can_reassign_visit(): void
    {
        $admin = $this->createUserWithRole('admin');
        $firstAgent = $this->createUserWithRole('agent');
        $secondAgent = $this->createUserWithRole('agent');
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);
        $visit = $this->createVisit($customer, $property, $firstAgent);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/visits/{$visit->id}/assign", [
                'assigned_user_id' => $secondAgent->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.assigned_agent.id', $secondAgent->id);

        $this->assertDatabaseHas('visits', [
            'id' => $visit->id,
            'assigned_agent_id' => $secondAgent->id,
        ]);
    }

    public function test_admin_can_unassign_visit(): void
    {
        $admin = $this->createUserWithRole('admin');
        $agent = $this->createUserWithRole('agent');
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);
        $visit = $this->createVisit($customer, $property, $agent);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/visits/{$visit->id}/assign", [
                'assigned_user_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.assigned_agent', null);

        $this->assertDatabaseHas('visits', [
            'id' => $visit->id,
            'assigned_agent_id' => null,
        ]);
    }

    public function test_non_admin_cannot_assign_visit(): void
    {
        $agent = $this->createUserWithRole('agent');
        $anotherAgent = $this->createUserWithRole('agent');
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);
        $visit = $this->createVisit($customer, $property);

        $this->actingAs($agent, 'sanctum')
            ->putJson("/api/v1/visits/{$visit->id}/assign", [
                'assigned_user_id' => $anotherAgent->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('visits', [
            'id' => $visit->id,
            'assigned_agent_id' => null,
        ]);
    }

    public function test_admin_cannot_assign_visit_to_customer_or_owner(): void
    {
        $admin = $this->createUserWithRole('admin');
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');
        $visitCustomer = $this->createUserWithRole('customer');

        $property = $this->createProperty($owner);
        $visit = $this->createVisit($visitCustomer, $property);

        foreach ([$customer, $owner] as $user) {
            $this->actingAs($admin, 'sanctum')
                ->putJson("/api/v1/visits/{$visit->id}/assign", [
                    'assigned_user_id' => $user->id,
                ])
                ->assertUnprocessable();
        }

        $this->assertDatabaseHas('visits', [
            'id' => $visit->id,
            'assigned_agent_id' => null,
        ]);
    }

    public function test_customer_visit_creation_is_recorded_in_activity_history(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');
        $property = $this->createProperty($owner);

        $scheduledAt = now()->addDays(3)->setTime(10, 0);

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/visits', [
                'property_id' => $property->id,
                'scheduled_at' => $scheduledAt->toDateTimeString(),
            ])
            ->assertCreated();

        $visitId = $response->json('data.id');

        $this->assertDatabaseHas('visit_activities', [
            'visit_id' => $visitId,
            'user_id' => $customer->id,
            'activity_type' => 'created',
            'new_status' => 'scheduled',
        ]);
    }

    public function test_agent_cannot_be_assigned_overlapping_visits(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');
        $agent = $this->createUserWithRole('agent');

        $firstProperty = $this->createProperty($owner, $agent);
        $secondProperty = $this->createProperty($owner, $agent);

        $scheduledAt = now()->addDays(3)->setTime(10, 0);

        $this->createVisit($customer, $firstProperty, $agent, [
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => 60,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/visits', [
                'property_id' => $secondProperty->id,
                'scheduled_at' => $scheduledAt->copy()->addMinutes(30)->toDateTimeString(),
                'duration_minutes' => 30,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('visits', 1);
    }

    public function test_back_to_back_visits_for_same_agent_are_allowed(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');
        $agent = $this->createUserWithRole('agent');

        $firstProperty = $this->createProperty($owner, $agent);
        $secondProperty = $this->createProperty($owner, $agent);

        $scheduledAt = now()->addDays(3)->setTime(10, 0);

        $this->createVisit($customer, $firstProperty, $agent, [
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => 60,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/visits', [
                'property_id' => $secondProperty->id,
                'scheduled_at' => $scheduledAt->copy()->addMinutes(60)->toDateTimeString(),
                'duration_minutes' => 30,
            ])
            ->assertCreated();

        $this->assertDatabaseCount('visits', 2);
    }

    public function test_different_agents_can_have_visits_at_same_time(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');
        $firstAgent = $this->createUserWithRole('agent');
        $secondAgent = $this->createUserWithRole('agent');

        $firstProperty = $this->createProperty($owner, $firstAgent);
        $secondProperty = $this->createProperty($owner, $secondAgent);

        $scheduledAt = now()->addDays(3)->setTime(10, 0);

        $this->createVisit($customer, $firstProperty, $firstAgent, [
            'scheduled_at' => $scheduledAt,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/visits', [
                'property_id' => $secondProperty->id,
                'scheduled_at' => $scheduledAt->toDateTimeString(),
            ])
            ->assertCreated();

        $this->assertDatabaseCount('visits', 2);
    }

    public function test_admin_cannot_assign_agent_with_overlapping_visit(): void
    {
        $admin = $this->createUserWithRole('admin');
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');
        $agent = $this->createUserWithRole('agent');

        $firstProperty = $this->createProperty($owner);
        $secondProperty = $this->createProperty($owner);

        $scheduledAt = now()->addDays(3)->setTime(10, 0);

        $this->createVisit($customer, $firstProperty, $agent, [
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => 60,
        ]);

        $unassignedVisit = $this->createVisit($customer, $secondProperty, null, [
            'scheduled_at' => $scheduledAt->copy()->addMinutes(30),
            'duration_minutes' => 30,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/visits/{$unassignedVisit->id}/assign", [
                'assigned_user_id' => $agent->id,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('visits', [
            'id' => $unassignedVisit->id,
            'assigned_agent_id' => null,
        ]);
    }

    public function test_scheduled_visit_cannot_be_completed_directly(): void
    {
        $admin = $this->createUserWithRole('admin');
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);
        $visit = $this->createVisit($customer, $property);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/visits/{$visit->id}/status", [
                'status' => 'completed',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('visits', [
            'id' => $visit->id,
            'status' => 'scheduled',
        ]);

        $this->assertDatabaseMissing('visit_activities', [
            'visit_id' => $visit->id,
            'new_status' => 'completed',
        ]);
    }

    public function test_confirmed_visit_cannot_return_to_scheduled(): void
    {
        $admin = $this->createUserWithRole('admin');
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);
        $visit = $this->createVisit($customer, $property, null, [
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/visits/{$visit->id}/status", [
                'status' => 'scheduled',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('visits', [
            'id' => $visit->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_status_change_is_recorded_in_activity_history(): void
    {
        $admin = $this->createUserWithRole('admin');
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);
        $visit = $this->createVisit($customer, $property);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/visits/{$visit->id}/status", [
                'status' => 'confirmed',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseHas('visit_activities', [
            'visit_id' => $visit->id,
            'user_id' => $admin->id,
            'activity_type' => 'status_changed',
            'old_status' => 'scheduled',
            'new_status' => 'confirmed',
        ]);
    }

    public function test_customer_cancellation_is_recorded_in_activity_history(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);
        $visit = $this->createVisit($customer, $property);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/v1/visits/{$visit->id}/status", [
                'status' => 'cancelled',
                'cancellation_reason' => 'Schedule changed.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('visit_activities', [
            'visit_id' => $visit->id,
            'user_id' => $customer->id,
            'activity_type' => 'status_changed',
            'old_status' => 'scheduled',
            'new_status' => 'cancelled',
        ]);
    }

    public function test_customer_can_reschedule_own_scheduled_visit(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');
        $property = $this->createProperty($owner);

        $visit = $this->createVisit($customer, $property);

        $newDate = now()->addDays(5)->setTime(11, 0);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/v1/visits/{$visit->id}/reschedule", [
                'scheduled_at' => $newDate->toDateTimeString(),
                'duration_minutes' => 60,
            ])
            ->assertOk()
            ->assertJsonPath('data.duration_minutes', 60);

        $this->assertDatabaseHas('visits', [
            'id' => $visit->id,
            'duration_minutes' => 60,
        ]);

        $this->assertEquals(
            $newDate->toDateTimeString(),
            $visit->fresh()->scheduled_at->toDateTimeString()
        );
    }

    public function test_customer_cannot_reschedule_another_customers_visit(): void
    {
        $customer = $this->createUserWithRole('customer');
        $anotherCustomer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);
        $visit = $this->createVisit($anotherCustomer, $property);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/v1/visits/{$visit->id}/reschedule", [
                'scheduled_at' => now()->addDays(5)->toDateTimeString(),
            ])
            ->assertForbidden();
    }

    public function test_customer_cannot_reschedule_confirmed_visit(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');
        $property = $this->createProperty($owner);

        $visit = $this->createVisit($customer, $property, null, [
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/v1/visits/{$visit->id}/reschedule", [
                'scheduled_at' => now()->addDays(5)->toDateTimeString(),
            ])
            ->assertUnprocessable();
    }

    public function test_visit_cannot_be_rescheduled_to_a_past_date(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');
        $property = $this->createProperty($owner);

        $visit = $this->createVisit($customer, $property);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/v1/visits/{$visit->id}/reschedule", [
                'scheduled_at' => now()->subDay()->toDateTimeString(),
            ])
            ->assertUnprocessable();
    }

    public function test_rescheduling_rejects_agent_schedule_conflict(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');
        $agent = $this->createUserWithRole('agent');

        $firstProperty = $this->createProperty($owner, $agent);
        $secondProperty = $this->createProperty($owner, $agent);

        $scheduledAt = now()->addDays(5)->setTime(10, 0);

        $this->createVisit($customer, $firstProperty, $agent, [
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => 60,
        ]);

        $visitToReschedule = $this->createVisit(
            $customer,
            $secondProperty,
            $agent,
            [
                'scheduled_at' => $scheduledAt->copy()->addHours(3),
                'duration_minutes' => 30,
            ]
        );

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/v1/visits/{$visitToReschedule->id}/reschedule", [
                'scheduled_at' => $scheduledAt->copy()
                    ->addMinutes(30)
                    ->toDateTimeString(),
            ])
            ->assertUnprocessable();

        $this->assertEquals(
            $scheduledAt->copy()->addHours(3)->toDateTimeString(),
            $visitToReschedule->fresh()->scheduled_at->toDateTimeString()
        );
    }

    public function test_back_to_back_rescheduled_visits_for_agent_are_allowed(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');
        $agent = $this->createUserWithRole('agent');

        $firstProperty = $this->createProperty($owner, $agent);
        $secondProperty = $this->createProperty($owner, $agent);

        $scheduledAt = now()->addDays(5)->setTime(10, 0);

        $this->createVisit($customer, $firstProperty, $agent, [
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => 60,
        ]);

        $visit = $this->createVisit($customer, $secondProperty, $agent, [
            'scheduled_at' => $scheduledAt->copy()->addHours(3),
            'duration_minutes' => 30,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/v1/visits/{$visit->id}/reschedule", [
                'scheduled_at' => $scheduledAt->copy()
                    ->addMinutes(60)
                    ->toDateTimeString(),
            ])
            ->assertOk();
    }

    public function test_rescheduling_is_recorded_in_activity_history(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');
        $property = $this->createProperty($owner);

        $visit = $this->createVisit($customer, $property);

        $newDate = now()->addDays(6)->setTime(14, 0);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/v1/visits/{$visit->id}/reschedule", [
                'scheduled_at' => $newDate->toDateTimeString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.activities.0.activity_type', 'rescheduled');
        $this->assertDatabaseHas('visit_activities', [
            'visit_id' => $visit->id,
            'user_id' => $customer->id,
            'activity_type' => 'rescheduled',
            'subject' => 'Visit rescheduled',
        ]);
    }

}
