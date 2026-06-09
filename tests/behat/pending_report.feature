@block @block_feedback_tracker @javascript
Feature: Pending grading report page renders for a course teacher
  In order to triage pending submissions across my groups
  As an editing teacher
  I need the Feedback Flow pending report page to list the submissions

  Background:
    Given the following "courses" exist:
      | fullname  | shortname |
      | Course 1  | C1        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
      | student1 | Sam       | Student  | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |

  Scenario: Teacher opens the report on a course with no pending submissions
    Given I log in as "teacher1"
    When I am on the "Course 1" "block_feedback_tracker > Pending report" page
    Then I should see "Pending grading"
    And I should see "Back to course"
    And I should see "No pending submissions match the current filter."
