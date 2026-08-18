<?php

/**
 * WWHN-300 / DEV300-P1-04 — allowlist tường minh cho migration fail-forward.
 *
 * Một migration có down() rỗng biến `migrate:rollback` thành no-op cục bộ: schema
 * đi tiếp còn code lùi lại. Với chuỗi migration của HoaNam đây là quyết định CÓ
 * CHỦ Ý — các bước dưới đây di chuyển dữ liệu sang bảng canonical theo hợp đồng
 * vật lý HN-WMS-ERD-DD-001, và down() không dựng lại được dữ liệu cũ.
 *
 * Thẩm quyền: Tech Lead — WWHN-300 (subtask WWHN-80), disposition ghi tại
 * docs/evidence/WWHN-300/CUTOVER_ROLLBACK_RUNBOOK.md §"Ranh giới rollback".
 * Hệ quả vận hành: qua các bước này, đường lùi hợp lệ duy nhất là restore backup
 * (thẩm quyền DevOps), KHÔNG phải migrate:rollback.
 *
 * Gate `wms:golive-check` so tập fail-forward THỰC TẾ với đúng danh sách này:
 *   - phát hiện một migration fail-forward KHÔNG có trong danh sách  -> FAIL
 *   - một mục trong danh sách nay đã có down() thật                  -> WARN (stale, gỡ khỏi đây)
 *
 * Thêm mục mới vào file này là một hành vi review được: nó tuyên bố rằng bước
 * migration đó chấp nhận mất đường rollback schema, và phải có người duyệt.
 *
 * Sinh lần đầu: WWHN-300 closure trên nhánh m1. KHÔNG sinh tự động lại — sửa tay
 * kèm review.
 *
 * @return array<string, string> tên migration => lý do fail-forward
 */

return [
    '2026_08_02_000002_safe_guard_products_to_sku_rename' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_09_000410_drop_non_erd_unused_tables' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_09_000420_harden_latest_mvp_contract' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000500_align_documented_schema_contract' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000590_seed_canonical_masters_before_fk_hardening' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000600_enforce_current_erd_business_contract' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000610_repoint_sku_to_canonical_masters' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000620_remove_superseded_fields_and_lock_append_only_history' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000630_repoint_scan_evidence_to_canonical_physical_codes' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000640_materialize_putaway_events' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000650_align_container_subledger_quantity' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000660_finalize_current_baseline_contract' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000665_backfill_legacy_container_physical_code_targets' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000670_align_identity_container_and_scan_lifecycle' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000680_align_putaway_and_container_compatibility' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000690_complete_component_issue_storage_contract' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000700_enforce_scan_evidence_storage_invariants' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000710_enforce_cross_aggregate_business_guards' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000720_enforce_all_documented_quantities_bigint' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000730_add_version_to_remaining_mutable_aggregates' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000740_verify_mutable_aggregate_version_contract' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000760_align_erd_1_0_fields_types_and_data' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000765_keep_legacy_runtime_and_erd_physical_schema_in_sync' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000766_backfill_structured_erd_payload_columns' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_0007674_add_missing_defect_catalog_runtime_columns' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_0007675_prepare_defect_catalog_canonical_key' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000767_complete_import_batch_runtime_compatibility' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000768_sync_defect_catalog_runtime_to_erd' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_0007695_prepare_all_canonical_erd_keys' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_0007697_backfill_iam_canonical_required_fields' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_0007698_backfill_warranty_canonical_required_fields' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000769_remove_superseded_label_print_event_status_guard' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000770_enforce_erd_1_0_keys_relationships_and_constraints' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000772_promote_documented_canonical_primary_keys' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000773_sync_warranty_runtime_to_erd' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000774_sync_iam_runtime_to_erd' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000780_enforce_cross_table_erd_business_invariants' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000781_enable_putaway_history_guard' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000782_enforce_item_tracking_and_container_sku_rules' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000783_enforce_documented_nonpartial_unique_indexes' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000784_enforce_remaining_erd_cross_aggregate_rules' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000790_retire_legacy_runtime_tables' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_10_000792_drop_retired_runtime_normalizers' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_12_130500_align_putaway_draft_confirmation_lifecycle' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_12_170000_drop_stale_defect_catalog_runtime_mirror_trigger' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_12_170500_allow_noop_erd_mirror_resync_of_posted_documents' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_12_213000_fix_materialize_receipt_putaway_status_trigger' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_13_150000_expose_warranty_flow04_runtime_contract' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_15_150000_fix_label_snapshots_normalize_trigger_payload_column' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_15_160000_fix_outbound_request_import_rows_normalize_trigger_json_cast' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_16_120000_relax_physical_code_target_to_at_most_one' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_16_121000_project_publish_state_through_sku_compat_views' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_16_122000_restore_reversal_immutability_and_accessory_fks' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_16_123000_add_containers_id_default_and_products_write_trigger' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_16_124000_sync_product_masters_to_canonical_master_tables' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_16_125000_make_outbound_recipient_address_nullable' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_16_126000_drop_rfid_required_post_gate' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_16_127000_mirror_inventory_serials_to_item_instances' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_16_130000_scope_container_primary_sku_guard_to_carton_box' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_16_131000_allow_sku_target_in_physical_code_legacy_check' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_16_132000_materialize_reversal_physical_return_columns' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_16_133000_sync_idempotency_mirror_deletes' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_16_135000_sync_canonical_masters_back_to_product_masters' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_16_136000_align_label_snapshot_lifecycle_with_state_model' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_16_137000_fix_label_snapshot_immutability_guard_columns' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_17_000100_materialize_erd_columns_on_sqlite' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_17_000200_backfill_warranty_pii_retention_deadline' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_17_001000_align_reversal_read_views_with_erd' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_17_001100_align_reconciliation_incident_with_erd' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_17_100000_expose_canonical_sku_version_and_master_fks_on_products_view' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_17_141000_relax_outbound_recipient_address_on_all_drivers' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_18_000100_align_defect_catalogs_with_erd_dictionary' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_18_150000_fix_posting_events_posting_action_operation_overflow' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_18_150100_expose_posting_action_on_posting_event_legacy_view' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
    '2026_08_18_150200_add_posting_action_to_posting_event_sqlite' => 'down() rỗng có chủ ý — fail-forward đã dispositioned tại WWHN-300 (Tech Lead)',
];
