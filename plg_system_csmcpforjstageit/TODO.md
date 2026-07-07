# plg_system_csmcpforjstageit — TODO

## v1.0.0 shipped

- 3 status/inspection tools: `get_stageit_status`, `get_stageit_prechecks`, `list_stageit_backups`
- 4 long-runner start tools: `deploy_stageit`, `sync_stageit`, `remove_stageit`, `restore_stageit_backup`
- 4 continue tools: `continue_stageit_deploy`, `continue_stageit_sync`, `continue_stageit_remove`, `continue_stageit_restore`

Total: 11 tools. Chunking + resume-token infra in `StageItBootTrait` + `OperationRunTrait`.

## v1.1 — the "everything around the operations" pack

Not shipped in v1.0 because the priority was getting the long-runners tested first. Still all safe and fast to build once we've kicked the tires on v1.0. Rough scope:

**Configuration (3):**
- `get_stageit_settings` — read `administrator/components/com_stageit/params.php` into a normalised JSON shape. Covers `speed`, `accel`, `optimize`, `backup`, `akeeba`, `filetime`, `over`, `chunk_threshold`, `chunk_size`, `debug_log`, `log_level`, `tables[]`, `folders[]`, plus the `license` and `email` fields.
- `update_stageit_settings` — partial-update patch for the above. Uses `vbParams::_saveParams()` so file locking + validation match the admin UI.
- `save_stageit_license` — dedicated tool for the license email + key pair (surfaced separately because it has UX weight — the license controls whether updates fire, not just runtime behaviour).

**Logs (4):**
- `read_stageit_log` — tail `administrator/logs/stageit-log.txt` with a line-count arg.
- `read_stageit_debug_log` — same for the debug log.
- `clear_stageit_log` — matches the admin's Clear Log button.
- `clear_stageit_debug_log` — same for debug log.

**Backup housekeeping (1):**
- `delete_stageit_backup` — remove one snapshot folder by name. Refuse to delete if it looks like the most recent (safety default) unless `force=true`.

**Inspection follow-ons (3):**
- `list_stageit_stg_tables` — list tables currently in the staging DB (prefix `stg_<real>`) with row counts. Complements `get_stageit_status`.
- `list_stageit_data_tables` — what would be mirrored on next deploy, given current ignore config.
- `list_stageit_ignore_config` — return `coreFolders` + `coreFiles` + `ignoreDirs` + `ignoreDb` + `stgPrefix` + `stgFolder` + `backFolder` constants.

**Precheck follow-on (1):**
- `check_stageit_ajax` — mirror of the `checkAjax` controller task — verifies the ajax endpoint round-trips end to end. Less useful for MCP than for the admin UI, but worth having for parity.

Total v1.1 target: ~12 more tools, bringing the plugin to ~23 tools total.

## Known unknowns / follow-ups from v1.0 testing

- **State-file cleanup.** Resume tokens live in `administrator/logs/stageit_mcp_state_<token>.json`. Currently only cleared on completion — if an operation is abandoned mid-flow, its state file leaks. Add a `list_stageit_operations` + `cancel_stageit_operation` pair to surface + clean orphan tokens. Or a stale-token cron sweep.
- **Sync stage graph is weird.** `stgAjaxSync::buildStgMap` transitions back to `action='init'` in one branch (`stgAjaxSync.class.php` line 77). Need to confirm during testing that this doesn't loop the state machine indefinitely under the MCP driver — the JS admin loop presumably handles it because init resets state.
- **Verify chdir doesn't leak across MCP calls in a shared PHP process.** The trait wraps stage calls in `withStageItCwd()` with a finally-restore, but if StageIt itself changes cwd mid-stage (some vbFiles calls do), the finally-restore may not restore the *original* pre-boot cwd. Worth a smoke test.
- **RestoreBackup + backup_name via `$_POST`.** The `runOperation()` sets `$_POST['backup'] = $backupName` before firing init — this is how StageIt's `stgAjaxRestoreBackup::init()` finds the target. On continue calls the backup name is already baked into the state (via the class instance's private props being reconstructed from the map file it wrote in init), so we shouldn't need to re-set it. Verify.
