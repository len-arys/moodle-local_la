<?php
// This file is part of Moodle - http://moodle.org/

namespace local_la\table;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->libdir . '/tablelib.php');

use html_writer;
use paging_bar;

/**
 * General local table with bottom-right pagination only.
 *
 * @package    local_la
 * @copyright  2026 Learning Analytics Contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class general_table extends \table_sql {
    /** @var bool Whether to wrap the table for horizontal scrolling. */
    public bool $responsive = true;

    /**
     * Start table HTML without top pagination.
     *
     * @return void
     */
    public function start_html() {
        echo $this->get_dynamic_table_html_start();
        echo $this->render_reset_button();
        $this->print_initials_bar();
        $this->wrap_html_start();

        if ($this->responsive) {
            echo html_writer::start_tag('div', ['class' => 'table-responsive']);
        }

        echo html_writer::start_tag('table', $this->attributes) . $this->render_caption();
    }

    /**
     * Finish table HTML with bottom-right pagination only.
     *
     * @return void
     */
    public function finish_html() {
        global $OUTPUT;

        if (!$this->started_output) {
            $this->print_nothing_to_display();
            return;
        }

        $emptyrow = array_fill(0, count($this->columns), '');
        while ($this->currentrow < $this->pagesize) {
            $this->print_row($emptyrow, 'emptyrow');
        }

        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');

        if ($this->responsive) {
            echo html_writer::end_tag('div');
        }

        $this->wrap_html_finish();

        if ($this->use_pages) {
            $pagingbar = new paging_bar($this->totalrows, $this->currpage, $this->pagesize, $this->baseurl);
            $pagingbar->pagevar = $this->request[TABLE_VAR_PAGE];
            echo html_writer::div($OUTPUT->render($pagingbar), 'd-flex justify-content-end mt-3');
        }

        echo $this->get_dynamic_table_html_end();
    }
}
