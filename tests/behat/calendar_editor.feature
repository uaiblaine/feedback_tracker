@block @block_feedback_tracker @javascript
Feature: Calendar editor accepts CSV bulk import
  In order to keep the academic-time engine accurate
  As an administrator
  I need to bulk-import calendar holidays from CSV

  Background:
    Given I log in as "admin"

  Scenario: Bulk import with one malformed row reports the error inline
    When I visit "/blocks/feedback_tracker/pages/calendar_editor.php"
    And I set the field "csv" to multiline:
      """
      2026-04-03, holiday, Good Friday
      2026-04-06, holiday, Easter Monday
      2026-13-99, holiday, Bad date
      2026-05-25, holiday, Memorial Day
      2026-07-04, recess, Mid-year break
      """
    And I press "Import"
    Then I should see "4 days saved"
    And I should see "1 errors"
    And I should see "Good Friday"
    And I should see "Easter Monday"
    And I should see "Memorial Day"

  Scenario: A single calendar day can be added and removed
    When I visit "/blocks/feedback_tracker/pages/calendar_editor.php"
    And I set the field "daydate" to "20260525"
    And I set the field "note" to "Memorial Day"
    And I press "Save changes"
    Then I should see "Calendar day saved"
    And I should see "20260525"
    And I should see "Memorial Day"
    When I click on "Delete" "link" in the "20260525" "table_row"
    Then I should see "Calendar day removed"
