<?php
/* For licensing terms, see /license.txt */

namespace Chamilo\PluginBundle\MigrationMoodle\Task;

use Chamilo\PluginBundle\MigrationMoodle\Extractor\CourseExtractor;
use Chamilo\PluginBundle\MigrationMoodle\Transformer\BaseTransformer;
use Chamilo\PluginBundle\MigrationMoodle\Transformer\Property\LoadedCourseLookup;
use Chamilo\PluginBundle\MigrationMoodle\Transformer\Property\LoadedLpQuizLookup;
use Chamilo\PluginBundle\MigrationMoodle\Transformer\Property\LoadedQuestionLookup;

/**
 * Class QuestionShortAnswerTask.
 *
 * @package Chamilo\PluginBundle\MigrationMoodle\Task
 */
class QuestionShortAnswerTask extends LessonAnswersShortAnswerTask
{
    /**
     * @inheritDoc
     */
    public function getExtractConfiguration()
    {
        return [
            'class' => CourseExtractor::class,
            'query' => "SELECT
                    qa.id,
                    qa.question,
                    GROUP_CONCAT(qa.answer SEPARATOR '|') answers,
                    GROUP_CONCAT(qa.fraction * qq.defaultmark) scores,
                    GROUP_CONCAT(qa.feedback SEPARATOR '\n') feedback,
                    COUNT(qa.id) nb,
                    q.id quizid,
                    q.course
                FROM mdl_question_answers qa
                INNER JOIN mdl_question qq ON qa.question = qq.id
                INNER JOIN mdl_qtype_shortanswer_options qo ON qq.id = qo.questionid
                INNER JOIN mdl_quiz_slots qs ON qq.id = qs.questionid
                INNER JOIN mdl_quiz q ON qs.quizid = q.id
                WHERE qq.qtype = 'shortanswer'
                GROUP BY qq.id
                ORDER BY q.course, qq.id",
        ];
    }

    /**
     * @inheritDoc
     */
    public function getTransformConfiguration()
    {
        return [
            'class' => BaseTransformer::class,
            'map' => [
                'c_id' => [
                    'class' => LoadedCourseLookup::class,
                    'properties' => ['course'],
                ],
                'quiz_id' => [
                    'class' => LoadedLpQuizLookup::class,
                    'properties' => ['quizid'],
                ],
                'question_id' => [
                    'class' => LoadedQuestionLookup::class,
                    'properties' => ['question'],
                ],
                'scores' => 'scores',
                'answers' => 'answers',
                'comment' => 'feedback',
                'nb' => 'nb',
            ],
        ];
    }
}
