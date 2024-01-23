<?php
/**
 * Plugin Name: Employee Feedback Form
 * Description: A simple plugin to collect employee feedback and display it in the admin dashboard.
 * Version: 1.0.2
 * Date: 2024-01-22
 * Author: PacificDev
 * Author URI: https://www.pacificdev.com/
 * License: MIT
 * Depends: PHP 7.0, WordPress 5.0
 * Developer: Jenny Martinez
 * Developer URI: http://github.com/lajennylove/
 * 
**/

if (!class_exists('EmployeeFeedbackForm')) {
    class EmployeeFeedbackForm {
        public function __construct() 
        {
            // Start a session
            session_start();

            // Check if Contact Form 7 is installed and activated
            if (class_exists('WPCF7')) {
                // Add the shortcode for the feedback form
                add_shortcode('employee_feedback_form', array($this, 'renderFeedbackForm'));

                // Create a menu item in the admin dashboard
                add_action('admin_menu', array($this, 'addMenuPage'));

                // Register a hook to handle form submission
                add_action('admin_post_submit_employee_feedback', array($this, 'handleFormSubmission'));
                add_action('admin_post_nopriv_submit_employee_feedback', array($this, 'handleFormSubmission'));

                // Enqueue JavaScript
                add_action('admin_enqueue_scripts', array($this, 'enqueueScripts'));

                // Handle AJAX request to delete feedback
                add_action('wp_ajax_delete_employee_feedback', array($this, 'deleteEmployeeFeedback'));
                add_action('wp_ajax_nopriv_delete_employee_feedback', array($this, 'deleteEmployeeFeedback'));

                // Activate/deactivate hooks
                register_activation_hook(__FILE__, array($this, 'activatePlugin'));
                register_deactivation_hook(__FILE__, array($this, 'deactivatePlugin'));
                register_uninstall_hook(__FILE__, array($this, 'uninstallPlugin'));
            } else {
                // Contact Form 7 is not installed, display a message
                add_action('admin_notices', array($this, 'cf7NotInstalledMessage'));
            }
        }

        // Render the feedback form
        public function renderFeedbackForm() 
        {
            ob_start(); ?>
            <style>
            .updated {
                background: var(--wp--preset--color--vivid-green-cyan);
                color: white;
                padding: 20px 10px 1px 10px;
                margin-top: 30px;
                font-weight: bold;
                line-height: 21px;
            }
            tt{
                color: var(--wp--preset--color--luminous-vivid-amber);
                size: 12px;
                
            }
            </style>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="submit_employee_feedback">
                <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">

                <label for="date">Date:</label>
                <input type="date" name="date" required><br>

                <label for="name">Developer Name:</label>
                <input type="text" name="name" required><br>

                <label for="jira_ticket">Jira Ticket Number: <tt>WPDB-XXXX</tt></label>
                <input type="text" name="jira_ticket" required><br>

                <label for="comments">Comments:</label>
                <textarea name="comments" required></textarea><br>

                <label for="blockers">Blockers:</label>
                <textarea name="blockers"></textarea><br><br>

                <input type="submit" name="submit_feedback" value="Submit">
            </form>

            <?php
            // Display a success message if the form was submitted successfully
            // Check if a success message is set in the session
            if ( isset($_SESSION['feedback_success']) ) {
                echo '<div class="updated"><p>' . esc_html($_SESSION['feedback_success']) . '</p></div>';
                // Clear the success message from the session to prevent it from showing again on page refresh
                unset($_SESSION['feedback_success']);
            }
            return ob_get_clean();
        }

        // Handle form submission
        public function handleFormSubmission() 
        {
            // Check if the form was submitted
            if (isset($_POST['action']) && $_POST['action'] === 'submit_employee_feedback') {
                $data = array(
                    'date' => sanitize_text_field($_POST['date']),
                    'name' => sanitize_text_field($_POST['name']),
                    'jira_ticket' => sanitize_text_field($_POST['jira_ticket']),
                    'comments' => sanitize_textarea_field($_POST['comments']),
                    'blockers' => sanitize_textarea_field($_POST['blockers']),
                );

                // Encode and store data in a transient
                set_transient('employee_feedback_' . time(), json_encode($data), 7 * DAY_IN_SECONDS); // Store for 7 days

                // Set a success message in the session
                $_SESSION['feedback_success'] = 'Feedback submitted successfully!';

                // Redirect back to the form page
                wp_safe_redirect($_POST['_wp_http_referer']);
                exit;
            }
        }

        // Add a menu page in the admin dashboard to display feedback data
        public function addMenuPage() 
        {
            add_menu_page('Feedback Data', 'Feedback Data', 'manage_options', 'feedback-data', array($this, 'displayFeedbackData'));
        }

        // Display feedback data in the admin dashboard
        public function displayFeedbackData() 
        {
            ?>
            <div class="wrap">
                <h2>Employee Feedback Data</h2>
                <table class="wp-list-table widefat fixed">
                    <thead>
                        <tr>
                            <th>Date Log</th>
                            <th>Date Register</th>
                            <th>Developer Name</th>
                            <th>Jira Ticket</th>
                            <th>Comments</th>
                            <th>Blockers</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        global $wpdb;
                        $table_name = $wpdb->options;
                        $transient_prefix = '_transient_employee_feedback_';

                        $transients = $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT option_name, option_value FROM {$table_name}
                                WHERE option_name LIKE %s
                                ORDER BY option_name DESC", // Order by option_name in descending order
                                $transient_prefix . '%'
                            )
                        );

                        if ( $transients ) {
                            foreach ( $transients as $transient ) {
                                $data = json_decode( $transient->option_value, true );
                                if ( $data ) {
                                    //store datetime in a variable from timestamp in transient name
                                    $datetime = date('Y-m-d H:i:s', substr($transient->option_name, strlen($transient_prefix)));

                                    echo '<tr>';
                                    echo '<td>' . $datetime . '</td>';
                                    echo '<td>' . esc_html($data['date']) . '</td>';
                                    echo '<td>' . esc_html($data['name']) . '</td>';
                                    echo '<td><a href="https://jira.cltbcanada.net/browse/' . esc_html($data['jira_ticket']) . '">' . esc_html($data['jira_ticket']) . '</a></td>';
                                    echo '<td>' . esc_html(stripslashes($data['comments'])) . '</td>';
                                    echo '<td>' . esc_html(stripslashes($data['blockers'])) . '</td>';
                                    echo '<td><button class="delete-feedback" data-transient="' . esc_attr($transient->option_name) . '">Delete</button></td>';
                                    echo '</tr>';
                                }
                            }
                        } 
                        else {
                            echo '<tr><td colspan="5">No data found.</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            <?php
        }

        // Display a notice if Contact Form 7 is not installed
        public function cf7NotInstalledMessage() 
        {
            ?>
            <div class="error">
                <p>Contact Form 7 is not installed. This plugin requires Contact Form 7 to function.</p>
            </div>
            <?php
        }

        // Enqueue JavaScript
        public function enqueueScripts() 
        {
            wp_enqueue_script( 'employee-feedback-script', plugin_dir_url(__FILE__) . 'employee-feedback-script.js', '', '1.0', true );

            // Pass the admin-ajax URL to JavaScript
            wp_localize_script( 'employee-feedback-script', 'employee_feedback_data', array(
                'ajax_url' => admin_url('admin-ajax.php'),
            ));
        }

        // Handle AJAX request to delete feedback
        public function deleteEmployeeFeedback() 
        {
            if ( isset( $_REQUEST['transient_name'] ) ) {
                $transientName = sanitize_text_field( $_POST['transient_name'] );

                // Remove the "_transient_" prefix
                $transientName = str_replace( '_transient_', '', $transientName );

                // Delete the transient
                delete_transient( $transientName );

                echo 'success';
            } 
            else {
                echo 'error';
            }

            exit;
        }

        // Activate the plugin
        public function activatePlugin() 
        {
            // Create a new page with the title "Employee Feedback" and content
            $page_title = 'Employee Feedback';
            $page_content = '<p>Please fill the following form to send your report.</p><br>[employee_feedback_form]';
            $page_slug = 'employee-feedback';

            // Check if the page exists
            $existing_page = get_page_by_path($page_slug);

            if (!$existing_page) {
                // Page doesn't exist, create a new one
                $page = array(
                    'post_title' => $page_title,
                    'post_content' => $page_content,
                    'post_name' => $page_slug,
                    'post_status' => 'publish',
                    'post_type' => 'page',
                );

                // Insert the page into the database
                wp_insert_post($page);
            } elseif ($existing_page->post_status === 'draft') {
                // Page exists but is in 'draft' status, update it to 'publish'
                $existing_page->post_status = 'publish';
                wp_update_post($existing_page);
            }
        }

        // Deactivate the plugin
        public function deactivatePlugin() 
        {
            // Get the page ID by slug
            $page = get_page_by_path('employee-feedback');

            // Check if the page exists
            if ($page) {
                // Set the page status to 'draft' when deactivating the plugin
                $page->post_status = 'draft';
                wp_update_post($page);
            }
        }

        // Uninstall the plugin
        public function uninstallPlugin() 
        {
            // Delete all transients created by the plugin
            global $wpdb;
            $transient_prefix = '_transient_employee_feedback_';
            $transients = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options}
                    WHERE option_name LIKE %s",
                    $transient_prefix . '%'
                )
            );

            if ($transients) {
                foreach ( $transients as $transient ) {
                     // Remove the "_transient_" prefix
                    $transientName = str_replace( '_transient_', '', $transient );
                    delete_transient( $transientName );
                }
            }

            // Get the page ID by slug
            $page = get_page_by_path('employee-feedback');

            // Check if the page exists
            if ( $page ) {
                // Delete the page permanently
                wp_delete_post( $page->ID, true );
            }
        }
    }

    $employee_feedback_form = new EmployeeFeedbackForm();
}
