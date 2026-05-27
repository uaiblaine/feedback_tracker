@block @block_feedback_tracker @javascript
Feature: Teacher dashboard renders for a multi-course editing teacher
  In order to triage feedback turnaround across my courses
  As an editing teacher
  I need the Feedback Flow teacher dashboard to render the hero + courses table

  Background:
    Given the following "courses" exist:
      | fullname  | shortname |
      | Course A  | CA        |
      | Course B  | CB        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | CA     | editingteacher |
      | teacher1 | CB     | editingteacher |
    # Editingteacher's archetype-default viewdashboard should propagate
    # automatically, but cap propagation can be flaky in Behat envs when
    # a plugin-defined cap meets a course-context archetype role. Set
    # explicitly at system level so the scenario doesn't depend on the
    # implicit chain.
    And the following "permission overrides" exist:
      | capability                              | permission | role           | contextlevel | reference |
      | block/feedback_tracker:viewdashboard    | Allow      | editingteacher | System       |           |

  Scenario: Editing teacher sees the hero greeting and the courses heading
    Given I log in as "teacher1"
    When I am on the "block_feedback_tracker > Teacher dashboard" page
    Then I should see "Terry"
    And I should see "Your courses"

  # Negative path (student lacks viewdashboard → page throws
  # required_capability_exception) is covered by PHPUnit
  # get_dashboard_test::test_student_is_rejected, not here. Moodle's
  # behat_navigation auto-detects rendered exceptions during
  # `I am on the page` and re-throws them as Behat failures, so
  # "user is intentionally blocked" scenarios don't translate cleanly.
