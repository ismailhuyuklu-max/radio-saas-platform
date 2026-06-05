import { defineComponent, h, reactive, ref, type Component } from 'vue';

import { Checkbox, DatePicker, Form, Input, InputNumber, Radio, Select } from 'ant-design-vue';

type FormComponentName =
  | 'Input'
  | 'RadioGroup'
  | 'CheckboxGroup'
  | 'InputNumber'
  | 'Select'
  | 'DatePicker'
  | 'RangePicker';

interface FormSchemaItem {
  component: FormComponentName;
  fieldName: string;
  label: string;
  rules?: 'required' | Array<{ required?: boolean; message?: string }>;
  componentProps?: Record<string, unknown>;
}

interface VbenFormOptions {
  layout?: 'horizontal' | 'vertical' | 'inline';
  commonConfig?: {
    componentProps?: Record<string, unknown>;
  };
  schema?: FormSchemaItem[];
  handleSubmit?: (values: Record<string, unknown>) => void | Promise<void>;
}

const componentMap: Record<FormComponentName, Component> = {
  Input,
  RadioGroup: Radio.Group,
  CheckboxGroup: Checkbox.Group,
  InputNumber,
  Select,
  DatePicker,
  RangePicker: DatePicker.RangePicker,
};

export const Page = defineComponent({
  name: 'VbenPage',
  props: {
    title: {
      type: String,
      default: '',
    },
    description: {
      type: String,
      default: '',
    },
  },
  setup(props, { slots }) {
    return () =>
      h('section', { class: 'vben-page-shell' }, [
        h('header', { class: 'vben-page-header' }, [
          props.title ? h('h1', { class: 'vben-page-title' }, props.title) : null,
          props.description
            ? h('p', { class: 'vben-page-description' }, props.description)
            : null,
        ]),
        h('div', { class: 'vben-page-body' }, slots.default?.()),
      ]);
  },
});

export function useVbenForm(options: VbenFormOptions = {}) {
  const schema = options.schema ?? [];
  const formState = reactive<Record<string, unknown>>({});
  const initialState = ref<Record<string, unknown>>({});

  function syncInitialState() {
    const snapshot: Record<string, unknown> = {};

    schema.forEach((item) => {
      snapshot[item.fieldName] = formState[item.fieldName];
    });

    initialState.value = snapshot;
  }

  syncInitialState();

  function setValues(values: Record<string, unknown>) {
    Object.entries(values).forEach(([key, value]) => {
      formState[key] = value;
    });
  }

  function resetForm() {
    Object.keys(formState).forEach((key) => {
      delete formState[key];
    });

    Object.entries(initialState.value).forEach(([key, value]) => {
      formState[key] = value;
    });
  }

  async function submitForm() {
    if (typeof options.handleSubmit === 'function') {
      await options.handleSubmit({ ...formState });
    }
  }

  const BasicForm = defineComponent({
    name: 'VbenBasicForm',
    setup(_, { expose }) {
      expose({
        setValues,
        resetForm,
        submitForm,
      });

      return () =>
        h(
          Form,
          {
            layout: options.layout ?? 'horizontal',
            model: formState,
            class: 'vben-basic-form',
            ...options.commonConfig?.componentProps,
          },
          () =>
            schema.map((item) => {
              const component = componentMap[item.component];
              const rules =
                item.rules === 'required'
                  ? [{ required: true, message: `${item.label} alanı zorunludur.` }]
                  : item.rules;

              return h(
                Form.Item,
                {
                  key: item.fieldName,
                  label: item.label,
                  name: item.fieldName,
                  rules,
                },
                () =>
                  h(component, {
                    value: formState[item.fieldName],
                    'onUpdate:value': (value: unknown) => {
                      formState[item.fieldName] = value;
                    },
                    ...(item.componentProps ?? {}),
                  }),
              );
            }),
        );
    },
  });

  return [BasicForm, { setValues, resetForm, submitForm }] as const;
}
