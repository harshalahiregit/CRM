<?php

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * A dropdown inside a dialog has to be drawn on top of that dialog.
 *
 * The searchable Select portals its popover to <body> with `position: fixed`,
 * and so does the modal Overlay. That makes them SIBLINGS in the root stacking
 * context, where the larger z-index simply wins. The popover carried z-80 and
 * the modal z-1000, so every dropdown opened underneath the dialog containing
 * it: the list rendered, held the right options, and could not be seen or
 * clicked. Reported as "worker registration can't select vendor" — it was every
 * select in every modal, across the whole application.
 *
 * Nothing else catches this. It builds, the component renders, the options are
 * correct, the API is fine, and no error reaches the console. Only the pixels
 * are wrong.
 *
 * Read as text because there is no JavaScript test runner in this repo — the
 * same approach BannedPatternsTest takes.
 */
class DropdownsSitAboveModalsTest extends TestCase
{
    private const UI = __DIR__.'/../../../../frontend/src/components/ui';

    /** The z-index the searchable Select's popover is drawn at. */
    private function popoverZ(): int
    {
        $src = file_get_contents(self::UI.'/Select.jsx');

        $this->assertMatchesRegularExpression('/className="fixed z-\[(\d+)\]/', $src,
            'the Select popover no longer declares a fixed z-index class');

        preg_match('/className="fixed z-\[(\d+)\]/', $src, $m);

        return (int) $m[1];
    }

    /** The highest z-index any modal shell is drawn at. */
    private function highestModalZ(): int
    {
        $src = file_get_contents(self::UI.'/kit3d.jsx');

        preg_match_all('/zIndex:\s*(\d+)/', $src, $m);
        $this->assertNotEmpty($m[1], 'no modal z-index found in kit3d');

        return max(array_map('intval', $m[1]));
    }

    public function test_the_select_popover_is_drawn_above_the_modal_it_sits_in(): void
    {
        $popover = $this->popoverZ();
        $modal = $this->highestModalZ();

        $this->assertGreaterThan($modal, $popover,
            "the Select popover (z-{$popover}) is below the modal shell (z-{$modal}); "
            .'both are portalled to <body>, so every dropdown inside every dialog '
            .'opens behind it and cannot be clicked');
    }

    public function test_the_popover_still_sits_under_the_toast_layer(): void
    {
        // Raising it above modals must not put it over the toasts, or an error
        // message about the very thing being chosen is hidden by the chooser.
        $this->assertLessThan(9998, $this->popoverZ(),
            'the Select popover must stay below the toast layer');
    }

    /**
     * The meeting participant picker draws its own panel the same way.
     *
     * It was written after this bug and already clears the modals, but it is the
     * other hand-positioned popover in the app and would fail the same way.
     */
    public function test_the_participant_picker_also_clears_the_modals(): void
    {
        $src = file_get_contents(__DIR__.'/../../../../frontend/src/components/meetings/ParticipantPicker.jsx');

        preg_match('/zIndex:\s*(\d+)/', $src, $m);
        $this->assertNotEmpty($m, 'the participant picker no longer sets a z-index');

        $this->assertGreaterThan($this->highestModalZ(), (int) $m[1],
            'the participant picker panel would open behind a modal');
    }
}
