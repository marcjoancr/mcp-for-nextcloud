<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** Exception-safe flow only. Connection identity, ownership and SQL remain in GroupLease. */
final class GroupLeaseFlow {
    public static function run(\Closure $acquire,\Closure $verify,\Closure $begin,\Closure $work,\Closure $commit,\Closure $release,\Closure $inTransaction,\Closure $rollback,\Closure $close):mixed {
        $failure=null;
        try {
            $acquire();$verify();$begin();$value=$work();$verify();$commit();return $value;
        } catch (\Throwable $error) {
            $failure=$error;
            try {if($inTransaction())$rollback();}catch(\Throwable){$close();}
            throw $error;
        } finally {
            try {$verify();$release();}
            catch(\Throwable $error){$close();if($failure===null)throw $error;}
        }
    }
}
