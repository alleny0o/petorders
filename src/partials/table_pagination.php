<?php
/**
 * Shared "Showing X-Y of Z" + page-size select + Prev/jump-to-page/Next
 * footer for every list page. Included (not called) from inside the
 * caller's own <div class="table-card">, so it renders in place exactly
 * where each page already had this markup.
 *
 * Expects a single $tablePagination array, assigned immediately before
 * this include, shaped:
 *   'idPrefix'     string  e.g. 'nuclides-' ('' is valid -- customer/orders.php)
 *   'itemLabel'    string  e.g. 'Nuclides' -- used in the sr-only "X per page" label
 *   'hiddenFields' array<string,string>  GET params (already-canonical
 *                  values) to preserve across the page-size/jump forms,
 *                  e.g. ['q' => $q, 'status' => $status] -- same set used
 *                  in both forms
 *   'page'         int  current (already DB-clamped) page
 *   'totalPages'   int
 *   'pageSize'     int
 *   'rangeStart'   int
 *   'rangeEnd'     int
 *   'totalCount'   int
 *
 * Deliberately reads $tablePagination[...] directly throughout rather than
 * aliasing to a short local like $p -- that name is already a live loop
 * variable on products.php/pis.php, so aliasing would risk clobbering it
 * once this include returns into the caller's scope.
 *
 * Pagination links are built via build_query() (helpers.php), which reads
 * $_GET -- the including page must have already canonicalized $_GET
 * (via canonicalize_get()) before this include, same as it always has.
 */
?>
<div class="table-pagination">
    <div class="table-pagination__status-group">
        <span class="table-pagination__status">Showing <?= $tablePagination['rangeStart'] ?>&ndash;<?= $tablePagination['rangeEnd'] ?> of <?= $tablePagination['totalCount'] ?></span>
        <form method="get" class="table-card-controls">
            <?php foreach ($tablePagination['hiddenFields'] as $name => $value): ?>
                <input type="hidden" name="<?= e($name) ?>" value="<?= e((string) $value) ?>">
            <?php endforeach; ?>
            <input type="hidden" name="page" value="1">
            <label for="<?= e($tablePagination['idPrefix']) ?>page-size" class="sr-only"><?= e($tablePagination['itemLabel']) ?> per page</label>
            <?php // data-auto-submit replaces an inline onchange= handler: inline event
                  // attributes are blocked by the Content-Security-Policy in
                  // send_security_headers() (finding M2). Wired in script.js. ?>
            <select name="page_size" id="<?= e($tablePagination['idPrefix']) ?>page-size" data-auto-submit>
                <?php foreach (PAGE_SIZE_OPTIONS as $option): ?>
                    <option value="<?= $option ?>" <?= $tablePagination['pageSize'] === $option ? 'selected' : '' ?>><?= $option ?> / page</option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="table-pagination__controls">
        <?php if ($tablePagination['page'] <= 1): ?>
            <span class="btn btn--secondary btn--sm" aria-disabled="true" aria-hidden="true">&lsaquo;</span>
        <?php else: ?>
            <a href="<?= e($_SERVER['PHP_SELF'] . build_query(['page' => $tablePagination['page'] - 1])) ?>" class="btn btn--secondary btn--sm" aria-label="Previous page">&lsaquo;</a>
        <?php endif; ?>
        <form method="get" class="table-card-controls table-pagination__jump">
            <?php foreach ($tablePagination['hiddenFields'] as $name => $value): ?>
                <input type="hidden" name="<?= e($name) ?>" value="<?= e((string) $value) ?>">
            <?php endforeach; ?>
            <?php if ($tablePagination['pageSize'] !== DEFAULT_PAGE_SIZE): ?>
                <input type="hidden" name="page_size" value="<?= e((string) $tablePagination['pageSize']) ?>">
            <?php endif; ?>
            <label for="<?= e($tablePagination['idPrefix']) ?>page-jump" class="sr-only">Go to page</label>
            <input type="number" name="page" id="<?= e($tablePagination['idPrefix']) ?>page-jump" min="1" max="<?= $tablePagination['totalPages'] ?>" value="<?= $tablePagination['page'] ?>">
            <span class="table-pagination__status">of <?= $tablePagination['totalPages'] ?></span>
            <button type="submit" class="btn btn--secondary btn--sm">Go</button>
        </form>
        <?php if ($tablePagination['page'] >= $tablePagination['totalPages']): ?>
            <span class="btn btn--secondary btn--sm" aria-disabled="true" aria-hidden="true">&rsaquo;</span>
        <?php else: ?>
            <a href="<?= e($_SERVER['PHP_SELF'] . build_query(['page' => $tablePagination['page'] + 1])) ?>" class="btn btn--secondary btn--sm" aria-label="Next page">&rsaquo;</a>
        <?php endif; ?>
    </div>
</div>
