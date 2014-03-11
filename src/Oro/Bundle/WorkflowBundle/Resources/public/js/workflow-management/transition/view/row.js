/* global define */
define(['underscore', 'backbone'],
function(_, Backbone) {
    'use strict';

    var $ = Backbone.$;

    /**
     * @export  oroworkflow/js/workflow-management/transition/view/row
     * @class   oro.WorkflowManagement.TransitionRowView
     * @extends Backbone.View
     */
    return Backbone.View.extend({
        tagName: 'tr',

        events: {
            'click .edit-transition': 'triggerEditTransition',
            'click .delete-transition': 'triggerRemoveTransition',
            'click .clone-transition': 'triggerCloneTransition'
        },

        options: {
            workflow: null,
            template: null,
            stepFrom: null
        },

        initialize: function() {
            var template = this.options.template || $('#transition-row-template').html();
            this.template = _.template(template);

            this.listenTo(this.model, 'change', this.render);
            this.listenTo(this.model, 'destroy', this.remove);
        },

        triggerEditTransition: function(e) {
            e.preventDefault();
            this.options.workflow.trigger('requestEditTransition', this.model, this.options.stepFrom);
        },

        triggerRemoveTransition: function(e) {
            e.preventDefault();
            this.options.workflow.trigger('requestRemoveTransition', this.model);
        },

        triggerCloneTransition: function(e) {
            e.preventDefault();
            this.options.workflow.trigger('requestCloneTransition', this.model, this.options.stepFrom);
        },

        render: function() {
            var data = this.model.toJSON();
            var stepTo = this.options.workflow.getStepByName(data.step_to);
            data.stepToLabel = stepTo ? stepTo.get('label') : '';
            this.$el.html(
                this.template(data)
            );

            return this;
        }
    });
});
