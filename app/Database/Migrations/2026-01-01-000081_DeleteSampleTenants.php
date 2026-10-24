<?php

namespace App\Database\Migrations;

use App\Libraries\AuditLogService;
use App\Models\TenantModel;
use CodeIgniter\Database\Migration;

// D-143 (project owner): "Punjab India Bank" and "XYZ Company" are not real
// tenants. Each is matched by id AND name (nothing else is touched). Its
// TSX Master role is removed — the person keeps their account — and the
// tenant is deleted only if nothing else still refers to it; otherwise it
// is left and the audit entry says what remains.
class DeleteSampleTenants extends Migration
{
    private const SAMPLES = [
        '25d8d8a8-7637-4ec3-a3c4-54cf9c8d4aa4' => 'Punjab India Bank',
        '550e8400-e29b-41d4-a716-446655440003' => 'XYZ Company',
    ];

    public function up()
    {
        $tenants = new TenantModel();
        $audit = new AuditLogService();
        foreach (self::SAMPLES as $id => $name) {
            $t = $this->db->table('tenant')->where('id', $id)->get()->getRowArray();
            if (!$t || $t['name'] !== $name || TenantModel::isSalvageManagers($t)) {
                continue;
            }
            $roles = $this->db->table('party_role')->select('party_id, role')->where('tenant_id', $id)->get()->getResultArray();
            $this->db->table('party_role')->where('tenant_id', $id)->delete();
            $left = $tenants->referenceCounts($id);
            if ($left) {
                $audit->log('tenant.sample_delete_skipped', null, ['tenantId' => $id, 'name' => $name, 'rolesRemoved' => $roles, 'stillReferencedBy' => $left]);
                continue;
            }
            $this->db->table('tenant')->where('id', $id)->delete();
            $audit->log('tenant.deleted', null, ['tenantId' => $id, 'name' => $name, 'subdomain' => $t['subdomain'], 'rolesRemoved' => $roles,
                'reason' => 'Sample tenant — not real (project owner, D-143)']);
        }
    }

    public function down()
    {
        // Deleted sample tenants are not recreated; the audit entries record what was removed.
    }
}
