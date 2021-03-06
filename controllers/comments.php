<?php
/**
 * @see http://freedesktop.org/wiki/Specifications/open-collaboration-services#CONTENT
 */
class com_meego_ocs_controllers_comments
{
    public function __construct(midgardmvc_core_request $request)
    {
        $this->request = $request;
    }

    public function get_get(array $args)
    {
        if ($args['type'] != 1)
        {
            throw new midgardmvc_exception_notfound("Only CONTENT type supported");
        }

        if ($args['contentid2'] != 0)
        {
            throw new midgardmvc_exception_notfound("No subcontent available");
        }

        $primary = new com_meego_package();
        $primary->get_by_id((int) $args['contentid1']);

        $storage = new midgard_query_storage('com_meego_comments_comment');
        $q = new midgard_query_select($storage);
        $q->set_constraint
        (
            new midgard_query_constraint
            (
                new midgard_query_property('to', $storage),
                '=',
                new midgard_query_value($primary->guid)
            )
        );

        $q->add_order(new midgard_query_property('metadata.created', $storage), SORT_ASC);

        // First run a query of the whole set to get results count
        $q->execute();
        $cnt = $q->get_results_count();

        list($limit, $offset) = $this->limit_and_offset_from_query();

        $q->set_limit($limit);
        $q->set_offset($offset);
        $q->execute();

        $comments = $q->list_objects();
        $comments_tree = array(); // <-- FIXME

        $ocs = new com_meego_ocs_OCSWriter();
        $ocs->writeMeta($cnt);

        $ocs->startElement('data');
        foreach ($comments_tree as $comment)
        {
            $this->comment_to_ocs($comment, $ocs);
        }
        $ocs->endElement();

        $ocs->endDocument();

        self::output_xml($ocs);
    }

    protected function limit_and_offset_from_query()
    {
        $query = $this->request->get_query();

        $page = 0;
        if (isset($query['page']))
        {
            $page = $query['page'];
        }

        $pagesize = midgardmvc_core::get_instance()->configuration->list_pagesize;
        if (isset($query['pagesize']))
        {
            $pagesize = $query['pagesize'];
        }

        return array($pagesize, $page * $pagesize);
    }

    private function comment_to_ocs(com_meego_comments_comment $comment, com_meego_ocs_OCSWriter $ocs)
    {
        $ocs->startElement('comment');
        $ocs->writeElement('id', $comment->id);
        $ocs->writeElement('subject', $comment->title);
        $ocs->writeElement('text', $comment->content);
        $comments = count($comment->subcomments);
        $ocs->writeElement('childcount', $comments);
        if ($comments > 0)
        {
            $ocs->startElement('children');
            foreach ($comment->subcomments as $subcomment)
            {
                $this->comment_to_ocs($subcomment, $ocs);
            }
            $ocs->endElement();
        }
        
        $ocs->writeElement('user', '');
        $ocs->writeElement('date', $comment->metadata->created->format('c'));
        $ocs->writeElement('score', $comment->metadata->score);
        $ocs->endElement();
    }

    public function post_add(array $args)
    {
        // Commenting requires basic auth
        $basic_auth = new midcom_core_services_authentication_basic();
        $e = new Exception("Comment posting requires Basic authentication");
        $basic_auth->handle_exception($e);

        $required_params = array
        (
            'type',
            'content',
            'message',
        );

        foreach ($required_params as $param)
        {
            if (   !isset($_POST[$param])
                || empty($_POST[$param]))
            {
                throw new midgardmvc_exception_notfound("Required parameter {$param} missing");
            }
        }

        if ($_POST['type'] != 1)
        {
            throw new midgardmvc_exception_notfound("Only CONTENT type supported");
        }

        $primary = new com_meego_package();
        $primary->get_by_id((int) $_POST['content']);

        $comment = new com_meego_comments_comment();

        if (   isset($_POST['parent'])
            && !empty($_POST['parent']))
        {
            $parent = new com_meego_comments_comment();
            $parent->get_by_id((int) $_POST['parent']);
            if ($parent->to != $primary->guid)
            {
                throw new midgardmvc_exception_notfound("Parent comment is not related to the content item");
            }
            $comment->up = $parent->id;
        }

        $comment->to = $primary->guid;
        $comment->content = $_POST['message'];

        if (   isset($_POST['subject'])
            && !empty($_POST['subject']))
        {
            $comment->title = $_POST['subject'];
        }

        $comment->create();

        $ocs = new com_meego_ocs_OCSWriter();
        $ocs->writeMeta(0);
        $ocs->endDocument();
        self::output_xml($ocs);
    }

    private static function output_xml($xml)
    {
        midgardmvc_core::get_instance()->dispatcher->header('Content-type: application/xml');
        echo $xml->outputMemory(true);

        midgardmvc_core::get_instance()->dispatcher->end_request();
    }
}
