<?php
/* For licensing terms, see /license.txt */

namespace Chamilo\CoreBundle\Controller;

use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\ToolChain;
use Chamilo\CourseBundle\Controller\ToolBaseController;
use Chamilo\CourseBundle\Entity\CTool;
use Chamilo\CourseBundle\Manager\SettingsManager;
use Chamilo\CourseBundle\Repository\CToolRepository;
use Chamilosession as Session;
use CourseManager;
use Database;
use Display;
use Event;
use ExtraFieldValue;
use Security;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Entity;
use Sylius\Bundle\SettingsBundle\Form\Factory\SettingsFormFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Exception\ValidatorException;

/**
 * Class CourseHomeController.
 *
 * @author Julio Montoya <gugli100@gmail.com>
 *
 * @Route("/course")
 */
class CourseHomeController extends ToolBaseController
{
    /**
     * @Route("/{cid}/home", name="chamilo_core_course_home")
     *
     * @Entity("course", expr="repository.find(cid)")
     */
    public function indexAction(Request $request, CToolRepository $toolRepository, ToolChain $toolChain)
    {
        $this->autoLaunch();
        $course = $this->getCourse();

        $js = '<script>'.api_get_language_translate_html().'</script>';
        $htmlHeadXtra[] = $js;

        $userId = $this->getUser()->getId();
        $courseCode = $course->getCode();
        $courseId = $course->getId();
        $sessionId = $this->getSessionId();

        if (api_is_invitee()) {
            $isInASession = $sessionId > 0;
            $isSubscribed = CourseManager::is_user_subscribed_in_course(
                $userId,
                $courseCode,
                $isInASession,
                $sessionId
            );

            if (!$isSubscribed) {
                api_not_allowed(true);
            }
        }

        // Deleting group session
        Session::erase('toolgroup');
        Session::erase('_gid');

        $isSpecialCourse = CourseManager::isSpecialCourse($courseId);

        if ($isSpecialCourse) {
            if (isset($_GET['autoreg']) && $_GET['autoreg'] == 1) {
                if (CourseManager::subscribeUser($userId, $courseCode, STUDENT)) {
                    Session::write('is_allowed_in_course', true);
                }
            }
        }

        $action = !empty($_GET['action']) ? Security::remove_XSS($_GET['action']) : '';
        if ($action == 'subscribe') {
            if (Security::check_token('get')) {
                Security::clear_token();
                $result = CourseManager::autoSubscribeToCourse($courseCode);
                if ($result) {
                    if (CourseManager::is_user_subscribed_in_course($userId, $courseCode)) {
                        Session::write('is_allowed_in_course', true);
                    }
                }
                header('Location: '.api_get_self());
                exit;
            }
        }

        /*  STATISTICS */
        if (!isset($coursesAlreadyVisited[$courseCode])) {
            Event::accessCourse();
            $coursesAlreadyVisited[$courseCode] = 1;
            Session::write('coursesAlreadyVisited', $coursesAlreadyVisited);
        }

        $logInfo = [
            'tool' => 'course-main',
            'action' => $action,
        ];
        Event::registerLog($logInfo);

        /*	Introduction section (editable by course admins) */
        /*$introduction = Display::return_introduction_section(
            TOOL_COURSE_HOMEPAGE,
            [
                'CreateDocumentWebDir' => api_get_path(WEB_COURSE_PATH).api_get_course_path().'/document/',
                'CreateDocumentDir' => 'document/',
                'BaseHref' => api_get_path(WEB_COURSE_PATH).api_get_course_path().'/',
            ]
        );*/

        $qb = $toolRepository->getResourcesByCourse($course, $this->getSession());
        $result = $qb->getQuery()->getResult();

        $tools = [];
        /** @var CTool $item */
        foreach ($result as $item) {
            $toolModel = $toolChain->getToolFromName($item->getTool()->getName());

            if ($toolModel->getCategory() === 'admin' && !$this->isGranted('ROLE_CURRENT_COURSE_TEACHER')) {
                continue;
            }
            $tools[$item->getCategory()][] = $item;
        }

        // Get session-career diagram
        $diagram = '';
        $allow = api_get_configuration_value('allow_career_diagram');
        if ($allow === true) {
            $htmlHeadXtra[] = api_get_js('jsplumb2.js');
            $extra = new ExtraFieldValue('session');
            $value = $extra->get_values_by_handler_and_field_variable(
                api_get_session_id(),
                'external_career_id'
            );

            if (!empty($value) && isset($value['value'])) {
                $careerId = $value['value'];
                $extraFieldValue = new ExtraFieldValue('career');
                $item = $extraFieldValue->get_item_id_from_field_variable_and_field_value(
                    'external_career_id',
                    $careerId,
                    false,
                    false,
                    false
                );

                if (!empty($item) && isset($item['item_id'])) {
                    $careerId = $item['item_id'];
                    $career = new Career();
                    $careerInfo = $career->get($careerId);
                    if (!empty($careerInfo)) {
                        $extraFieldValue = new ExtraFieldValue('career');
                        $item = $extraFieldValue->get_values_by_handler_and_field_variable(
                            $careerId,
                            'career_diagram',
                            false,
                            false,
                            false
                        );

                        if (!empty($item) && isset($item['value']) && !empty($item['value'])) {
                            /** @var Graph $graph */
                            $graph = UnserializeApi::unserialize(
                                'career',
                                $item['value']
                            );
                            $diagram = Career::renderDiagram($careerInfo, $graph);
                        }
                    }
                }
            }
        }

        // Deleting the objects
        Session::erase('_gid');
        Session::erase('oLP');
        Session::erase('lpobject');
        api_remove_in_gradebook();
        \Exercise::cleanSessionVariables();
        \DocumentManager::removeGeneratedAudioTempFile();

        return $this->render(
            '@ChamiloTheme/Course/home.html.twig',
            [
                'course' => $course,
                'diagram' => $diagram,
               // 'session_info' => $sessionInfo,
                'tools' => $tools,
                //'edit_icons' => $editIcons,
                //'introduction_text' => $introduction,
                'exercise_warning' => null,
                'lp_warning' => null,
            ]
        );
    }

    /**
     * @Route("/{cid}/tool/{toolId}", name="chamilo_core_course_redirect_tool")
     */
    public function redirectTool($toolId, ToolChain $toolChain)
    {
        $criteria = ['id' => $toolId];
        /** @var CTool $tool */
        $tool = $this->getDoctrine()->getRepository('Chamilo\CourseBundle\Entity\CTool')->findOneBy($criteria);

        if (null === $tool) {
            throw new NotFoundHttpException($this->trans('Tool not found'));
        }

        $tool = $toolChain->getToolFromName($tool->getTool()->getName());
        $url = $tool->getLink().'?'.$this->getCourseUrlQuery();

        return $this->redirect($url);
    }

    /**
     * Edit configuration with given namespace.
     *
     * @param string $namespace
     * @Route("/{cid}/settings/{namespace}", name="chamilo_core_course_settings")

     * @Entity("course", expr="repository.find(cid)")
     *
     * @return Response
     */
    public function updateAction(Request $request, Course $course, $namespace, SettingsManager $manager, SettingsFormFactory $formFactory)
    {
        $schemaAlias = $manager->convertNameSpaceToService($namespace);

        $settings = $manager->load($namespace);
        $form = $formFactory->create($schemaAlias);

        $form->setData($settings);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $messageType = 'success';
            try {
                $manager->setCourse($course);
                $manager->save($form->getData());
                $message = $this->trans('Update');
            } catch (ValidatorException $exception) {
                $message = $this->trans(
                    $exception->getMessage(),
                    [],
                    'validators'
                );
                $messageType = 'error';
            }
            $request->getSession()->getBag('flashes')->add(
                $messageType,
                $message
            );

            if ($request->headers->has('referer')) {
                return $this->redirect($request->headers->get('referer'));
            }
        }
        $schemas = $manager->getSchemas();

        return $this->render(
            '@ChamiloTheme/Course/settings.html.twig',
            [
                'course' => $course,
                'schemas' => $schemas,
                'settings' => $settings,
                'form' => $form->createView(),
            ]
        );
    }

    /**
     * @return array
     */
    private function autoLaunch()
    {
        /* Auto launch code */
        $autoLaunchWarning = '';
        $showAutoLaunchLpWarning = false;
        $course_id = api_get_course_int_id();
        $lpAutoLaunch = api_get_course_setting('enable_lp_auto_launch');
        $session_id = api_get_session_id();
        $allowAutoLaunchForCourseAdmins = api_is_platform_admin() || api_is_allowed_to_edit(true, true) || api_is_coach();

        if (!empty($lpAutoLaunch)) {
            if ($lpAutoLaunch == 2) {
                // LP list
                if ($allowAutoLaunchForCourseAdmins) {
                    $showAutoLaunchLpWarning = true;
                } else {
                    $session_key = 'lp_autolaunch_'.$session_id.'_'.api_get_course_int_id().'_'.api_get_user_id();
                    if (!isset($_SESSION[$session_key])) {
                        // Redirecting to the LP
                        $url = api_get_path(WEB_CODE_PATH).'lp/lp_controller.php?'.api_get_cidreq();
                        $_SESSION[$session_key] = true;
                        header("Location: $url");
                        exit;
                    }
                }
            } else {
                $lp_table = Database::get_course_table(TABLE_LP_MAIN);
                $condition = '';
                if (!empty($session_id)) {
                    $condition = api_get_session_condition($session_id);
                    $sql = "SELECT id FROM $lp_table
                            WHERE c_id = $course_id AND autolaunch = 1 $condition
                            LIMIT 1";
                    $result = Database::query($sql);
                    // If we found nothing in the session we just called the session_id =  0 autolaunch
                    if (Database::num_rows($result) == 0) {
                        $condition = '';
                    }
                }

                $sql = "SELECT id FROM $lp_table
                        WHERE c_id = $course_id AND autolaunch = 1 $condition
                        LIMIT 1";
                $result = Database::query($sql);
                if (Database::num_rows($result) > 0) {
                    $lp_data = Database::fetch_array($result, 'ASSOC');
                    if (!empty($lp_data['id'])) {
                        if ($allowAutoLaunchForCourseAdmins) {
                            $showAutoLaunchLpWarning = true;
                        } else {
                            $session_key = 'lp_autolaunch_'.$session_id.'_'.api_get_course_int_id().'_'.api_get_user_id();
                            if (!isset($_SESSION[$session_key])) {
                                // Redirecting to the LP
                                $url = api_get_path(WEB_CODE_PATH).'lp/lp_controller.php?'.api_get_cidreq().'&action=view&lp_id='.$lp_data['id'];

                                $_SESSION[$session_key] = true;
                                header("Location: $url");
                                exit;
                            }
                        }
                    }
                }
            }
        }

        if ($showAutoLaunchLpWarning) {
            $autoLaunchWarning = get_lang('The learning path auto-launch setting is ON. When learners enter this course, they will be automatically redirected to the learning path marked as auto-launch.');
        }

        $forumAutoLaunch = api_get_course_setting('enable_forum_auto_launch');
        if ($forumAutoLaunch == 1) {
            if ($allowAutoLaunchForCourseAdmins) {
                if (empty($autoLaunchWarning)) {
                    $autoLaunchWarning = get_lang('The forum\'s auto-launch setting is on. Students will be redirected to the forum tool when entering this course.');
                }
            } else {
                $url = api_get_path(WEB_CODE_PATH).'forum/index.php?'.api_get_cidreq();
                header("Location: $url");
                exit;
            }
        }

        if (api_get_configuration_value('allow_exercise_auto_launch')) {
            $exerciseAutoLaunch = (int) api_get_course_setting('enable_exercise_auto_launch');
            if ($exerciseAutoLaunch == 2) {
                if ($allowAutoLaunchForCourseAdmins) {
                    if (empty($autoLaunchWarning)) {
                        $autoLaunchWarning = get_lang(
                            'TheExerciseAutoLaunchSettingIsONStudentsWillBeRedirectToTheExerciseList'
                        );
                    }
                } else {
                    // Redirecting to the document
                    $url = api_get_path(WEB_CODE_PATH).'exercise/exercise.php?'.api_get_cidreq();
                    header("Location: $url");
                    exit;
                }
            } elseif ($exerciseAutoLaunch == 1) {
                if ($allowAutoLaunchForCourseAdmins) {
                    if (empty($autoLaunchWarning)) {
                        $autoLaunchWarning = get_lang(
                            'TheExerciseAutoLaunchSettingIsONStudentsWillBeRedirectToAnSpecificExercise'
                        );
                    }
                } else {
                    // Redirecting to an exercise
                    $table = Database::get_course_table(TABLE_QUIZ_TEST);
                    $condition = '';
                    if (!empty($session_id)) {
                        $condition = api_get_session_condition($session_id);
                        $sql = "SELECT iid FROM $table
                        WHERE c_id = $course_id AND autolaunch = 1 $condition
                        LIMIT 1";
                        $result = Database::query($sql);
                        // If we found nothing in the session we just called the session_id = 0 autolaunch
                        if (Database::num_rows($result) == 0) {
                            $condition = '';
                        }
                    }

                    $sql = "SELECT iid FROM $table
                    WHERE c_id = $course_id AND autolaunch = 1 $condition
                    LIMIT 1";
                    $result = Database::query($sql);
                    if (Database::num_rows($result) > 0) {
                        $row = Database::fetch_array($result, 'ASSOC');
                        $exerciseId = $row['iid'];
                        $url = api_get_path(WEB_CODE_PATH).
                            'exercise/overview.php?exerciseId='.$exerciseId.'&'.api_get_cidreq();
                        header("Location: $url");
                        exit;
                    }
                }
            }
        }

        $documentAutoLaunch = api_get_course_setting('enable_document_auto_launch');
        if ($documentAutoLaunch == 1) {
            if ($allowAutoLaunchForCourseAdmins) {
                if (empty($autoLaunchWarning)) {
                    $autoLaunchWarning = get_lang('The document auto-launch feature configuration is enabled. Learners will be automatically redirected to document tool.');
                }
            } else {
                // Redirecting to the document
                $url = api_get_path(WEB_CODE_PATH).'document/document.php?'.api_get_cidreq();
                header("Location: $url");
                exit;
            }
        }

        /*	SWITCH TO A DIFFERENT HOMEPAGE VIEW
         the setting homepage_view is adjustable through
         the platform administration section */
        if (!empty($autoLaunchWarning)) {
            $this->addFlash(
            Display::return_message(
                $autoLaunchWarning,
                'warning'
            ));
        }
    }
}