<?php
/**
 * @package Newscoop
 * @copyright 2011 Sourcefabric o.p.s.
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 */

use Newscoop\Entity\Acl\Role,
    Newscoop\Entity\Acl\Rule,
    Newscoop\Entity\User\Staff;

/**
 * @Acl(ignore="1")
 */
class Admin_AclController extends Zend_Controller_Action
{
    /** @var Resource_Acl */
    private $acl;

    /** @var array */
    private $ruleTypes;

    /** @var Doctrine\ORM\EntityRepository */
    private $ruleRepository;

    /** @var string */
    private $resource;

    public function init()
    {
        camp_load_translation_strings('user_types');

        $this->ruleRepository = $this->_helper->entity->getRepository('Newscoop\Entity\Acl\Rule');

        $this->ruleTypes = array(
            'allow' => getGS('Allow'),
            'deny' => getGS('Deny'),
        );

        $this->_helper->contextSwitch()
            ->addActionContext('actions', 'json')
            ->initContext();

        $this->_helper->contextSwitch()
            ->addActionContext('save', 'json')
            ->initContext();

        $this->acl = Zend_Registry::get('acl');

        $this->resource = $this->_getParam('user', false) ? 'user' : 'user-group';
    }

    public function formAction()
    {
        $this->_helper->acl->check($this->resource, 'manage');

        $form = $this->getForm()
            ->setAction('')
            ->setMethod('post')
            ->setDefaults(array(
                'type' => 'allow',
                'role' => $this->_getParam('role', 0),
                'group' => $this->_getParam('group', 0),
                'user' => $this->_getParam('user', 0),
            ));

        // form handle
        if ($this->getRequest()->isPost() && $form->isValid($_POST)) {
            $values = $form->getValues();
            $user = Zend_Registry::get('user');
            $acl = $this->_helper->acl->getAcl($user);

            // check if rule would deny user to manage permissions
            if (in_array($values['role'], $acl->getRoles()) && $values['type'] == 'deny') {
                $resource = empty($values['resource']) ? null : $values['resource'];
                $action = empty($values['action']) ? null : $values['action'];
                $acl->deny($values['role'], $resource, $action);

                if (!$acl->isAllowed($user, $this->resource, 'manage')) {
                    $this->_helper->flashMessenger(array('error', getGS("You can't deny yourself to manage $1", $this->formatName($this->resource))));
                    $this->redirect();
                }
            }

            try {
                $rule = new Rule();
                $this->ruleRepository->save($rule, $form->getValues());
                $this->_helper->entity->flushManager();

                $this->_helper->flashMessenger->addMessage(getGS('Rule saved.'));
                $this->redirect();
            } catch (PDOException $e) {
                $form->role->addError(getGS('Rule for this resource/action exists already.'));
            }
        }

        $this->view->form = $form;
    }

    public function editAction()
    {
        $this->view->resources = $this->acl->getResources();
        $this->view->acl = $this->getHelper('acl');
        $this->view->role = $this->_getParam('role');
    }

    public function saveAction()
    {
        try {
            $rule = new Rule();
            $request = $this->getRequest();
            $this->ruleRepository->save($rule, $request->getPost());
            $this->_helper->entity->flushManager();
            $this->view->status = 'ok';
        } catch (\Exception $e) {
            $this->view->status = 'error';
            $this->view->message = $e->getMessage();
        }
    }

    public function deleteAction()
    {
        $this->_helper->acl->check($this->resource, 'manage');

        $user = Zend_Registry::get('user');
        $acl = $this->_helper->acl->getAcl($user);
        $rule = $this->_helper->entity->find('Newscoop\Entity\Acl\Rule', $this->_getParam('rule'));

        // check if removing rule would prevent user to edit permissions
        if (in_array($rule->getRoleId(), $acl->getRoles())) {
            $method = 'remove' . ucfirst($rule->getType());
            $acl->$method($rule->getRoleId(), $rule->getResource(), $rule->getAction());
            if (!$acl->isAllowed($user, $this->resource, 'manage')) {
                $this->_helper->flashMessenger(array('error', getGS("You can't deny yourself to manage $1", $this->formatName($this->resource))));
                $this->redirect();
            }
        }

        $this->ruleRepository->delete($this->_getParam('rule'));
        $this->_helper->entity->flushManager();

        $this->_helper->flashMessenger->addMessage(getGS('Rule removed.'));
        $this->redirect();
    }

    /**
     * Get actions for resource
     */
    public function actionsAction()
    {
        $actions = array();
        $resource = $this->_getParam('resource', '');
        if (!empty($resource)) {
            $actions = Saas::singleton()->filterPrivileges($resource, $this->acl->getActions($resource));
        }

        $this->view->actions = $actions;
    }

    /**
     * Get rule form
     *
     * @return Zend_Form
     */
    private function getForm()
    {
        $form = new Zend_Form();

        $form->addElement('hidden', 'role', array(
            'filters' => array(
                array('int'),
            ),
        ));
        $form->addElement('hidden', 'group');
        $form->addElement('hidden', 'user');

        // get resources
        $resources = array('' => getGS('Any resource'));
        foreach (array_keys($this->acl->getResources()) as $resource) {
            $resources[$resource] = $this->formatName($resource);
        }

        $form->addElement('select', 'resource', array(
            'multioptions' => $resources,
            'label' => getGS('Resource'),
        ));

        // get actions
        $actions = array('' => getGS('Any action'));
        foreach ($this->acl->getActions() as $action) {
            $actions[$action] = $this->formatName($action);
        }

        $form->addElement('select', 'action', array(
            'multioptions' => $actions,
            'label' => getGS('Action'),
        ));

        $form->addElement('radio', 'type', array(
            'label' => getGS('Add Rule'),
            'multioptions' => $this->ruleTypes,
            'class' => 'acl type',
        ));

        $form->addElement('submit', 'submit', array(
            'label' => getGS('Add'),
        ));

        return $form;
    }

    /**
     * Redirect after action
     *
     * @return void
     */
    private function redirect()
    {
        $params = $this->getRequest()->getParams();
        $entity = !empty($params['group']) ? 'group' : 'user';

        $this->_helper->redirector('edit-access', $entity == 'group' ? 'user-group' : 'staff', 'admin', array(
            $entity => $params[$entity],
        ));
    }

    /**
     * Format name
     *
     * @param string $name
     * @return string
     */
    private function formatName($name)
    {
        $parts = explode('-', $name);
        $parts = array_map('ucfirst', $parts);
        return implode(' ', $parts);
    }
}
