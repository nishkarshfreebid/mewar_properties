<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PropertyAuthorizationTest extends TestCase
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
        string $approvalStatus = 'pending',
        string $publicationStatus = 'draft'
    ): Property {
        return Property::create([
            'owner_id' => $owner->id,
            'assigned_agent_id' => $agent?->id,
            'title' => 'Test Property',
            'slug' => 'test-property-' . uniqid(),
            'description' => 'Authorization test property',
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

    private function validPropertyPayload(): array
    {
        return [
            'title' => 'New Test Property',
            'description' => 'New property description',
            'property_type' => 'apartment',
            'listing_type' => 'sale',
            'price' => 6500000,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'area' => 1500,
            'area_unit' => 'sqft',
            'city' => 'Jaipur',
            'state' => 'Rajasthan',
            'country' => 'India',
        ];
    }

    public function test_unauthenticated_user_cannot_create_property(): void
    {
        $this->postJson('/api/v1/properties', $this->validPropertyPayload())
            ->assertUnauthorized();
    }

    public function test_customer_cannot_create_property(): void
    {
        $customer = $this->createUserWithRole('customer');

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/properties', $this->validPropertyPayload())
            ->assertForbidden();
    }

    public function test_owner_can_create_property(): void
    {
        $owner = $this->createUserWithRole('owner');

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/properties', $this->validPropertyPayload())
            ->assertCreated()
            ->assertJsonPath('data.owner_id', $owner->id)
            ->assertJsonPath('data.approval_status', 'pending')
            ->assertJsonPath('data.publication_status', 'draft');

        $this->assertDatabaseHas('properties', [
            'owner_id' => $owner->id,
            'title' => 'New Test Property',
        ]);
    }

    public function test_admin_can_create_property_for_an_owner(): void
    {
        $admin = $this->createUserWithRole('admin');
        $owner = $this->createUserWithRole('owner');

        $payload = array_merge(
            $this->validPropertyPayload(),
            ['owner_id' => $owner->id]
        );

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/properties', $payload)
            ->assertCreated()
            ->assertJsonPath('data.owner_id', $owner->id);
    }

    public function test_owner_can_update_own_property(): void
    {
        $owner = $this->createUserWithRole('owner');
        $property = $this->createProperty($owner);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/properties/{$property->id}", [
                'description' => 'Updated description',
            ])
            ->assertOk()
            ->assertJsonPath('data.description', 'Updated description');
    }

    public function test_owner_cannot_update_another_owners_property(): void
    {
        $ownerOne = $this->createUserWithRole('owner');
        $ownerTwo = $this->createUserWithRole('owner');

        $property = $this->createProperty($ownerOne);

        $this->actingAs($ownerTwo, 'sanctum')
            ->patchJson("/api/v1/properties/{$property->id}", [
                'description' => 'Unauthorized update',
            ])
            ->assertForbidden();
    }

    public function test_agent_can_update_assigned_property(): void
    {
        $owner = $this->createUserWithRole('owner');
        $agent = $this->createUserWithRole('agent');

        $property = $this->createProperty($owner, $agent);

        $this->actingAs($agent, 'sanctum')
            ->patchJson("/api/v1/properties/{$property->id}", [
                'description' => 'Agent updated description',
            ])
            ->assertOk()
            ->assertJsonPath('data.description', 'Agent updated description');
    }

    public function test_agent_cannot_update_unassigned_property(): void
    {
        $owner = $this->createUserWithRole('owner');
        $agent = $this->createUserWithRole('agent');

        $property = $this->createProperty($owner);

        $this->actingAs($agent, 'sanctum')
            ->patchJson("/api/v1/properties/{$property->id}", [
                'description' => 'Unauthorized agent update',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_update_any_property(): void
    {
        $admin = $this->createUserWithRole('admin');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/properties/{$property->id}", [
                'description' => 'Admin updated property',
            ])
            ->assertOk()
            ->assertJsonPath('data.description', 'Admin updated property');
    }

    public function test_customer_cannot_delete_property(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);

        $this->actingAs($customer, 'sanctum')
            ->deleteJson("/api/v1/properties/{$property->id}")
            ->assertForbidden();
    }

    public function test_admin_can_approve_property(): void
    {
        $admin = $this->createUserWithRole('admin');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/properties/{$property->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'approved');

        $this->assertDatabaseHas('properties', [
            'id' => $property->id,
            'approval_status' => 'approved',
            'approved_by' => $admin->id,
        ]);
    }

    public function test_admin_can_publish_approved_property(): void
    {
        $admin = $this->createUserWithRole('admin');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty(
            $owner,
            null,
            'approved',
            'draft'
        );

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/properties/{$property->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.publication_status', 'published');
    }

    public function test_customer_cannot_approve_property(): void
    {
        $customer = $this->createUserWithRole('customer');
        $owner = $this->createUserWithRole('owner');

        $property = $this->createProperty($owner);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/properties/{$property->id}/approve")
            ->assertForbidden();
    }

    public function test_staff_cannot_create_property(): void
    {
        $staff = $this->createUserWithRole('staff');

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/properties', $this->validPropertyPayload())
            ->assertForbidden();
    }

    public function test_public_property_listing_is_accessible_without_login(): void
    {
        $owner = $this->createUserWithRole('owner');

        $this->createProperty(
            $owner,
            null,
            'approved',
            'published'
        );

        $this->getJson('/api/v1/properties')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_agent_can_see_only_its_assigned_properties_in_admin_listing(): void
    {
        $owner = $this->createUserWithRole('owner');
        $agentA = $this->createUserWithRole('agent');
        $agentB = $this->createUserWithRole('agent');

        $assignedToA = $this->createProperty($owner, $agentA);
        $this->createProperty($owner, $agentB);
        $this->createProperty(
            $owner,
            null,
            'approved',
            'published'
        );

        $response = $this->actingAs($agentA, 'sanctum')
            ->getJson('/api/v1/admin/properties')
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $assignedToA->id);
    }

    public function test_agent_cannot_view_another_agents_property_even_if_published(): void
    {
        $owner = $this->createUserWithRole('owner');
        $agentA = $this->createUserWithRole('agent');
        $agentB = $this->createUserWithRole('agent');

        $property = $this->createProperty(
            $owner,
            $agentB,
            'approved',
            'published'
        );

        $this->actingAs($agentA, 'sanctum')
            ->getJson("/api/v1/properties/{$property->id}")
            ->assertForbidden();
    }

    public function test_agent_can_view_its_own_assigned_property(): void
    {
        $owner = $this->createUserWithRole('owner');
        $agent = $this->createUserWithRole('agent');

        $property = $this->createProperty($owner, $agent);

        $this->actingAs($agent, 'sanctum')
            ->getJson("/api/v1/properties/{$property->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $property->id);
    }

    public function test_agent_can_see_only_assigned_properties_in_mine_endpoint(): void
    {
        $owner = $this->createUserWithRole('owner');
        $agentA = $this->createUserWithRole('agent');
        $agentB = $this->createUserWithRole('agent');

        $assignedToA = $this->createProperty($owner, $agentA);
        $this->createProperty($owner, $agentB);

        $response = $this->actingAs($agentA, 'sanctum')
            ->getJson('/api/v1/properties/mine')
            ->assertOk();

        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.id', $assignedToA->id);
    }

    public function test_admin_can_see_all_properties_in_admin_listing(): void
    {
        $admin = $this->createUserWithRole('admin');
        $owner = $this->createUserWithRole('owner');
        $agent = $this->createUserWithRole('agent');

        $this->createProperty($owner);
        $this->createProperty($owner, $agent);
        $this->createProperty(
            $owner,
            null,
            'approved',
            'published'
        );

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/properties')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

}
