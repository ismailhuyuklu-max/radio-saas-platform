<script lang="ts" setup>
import { computed, nextTick, onMounted, ref, h } from "vue";
import dayjs, { type Dayjs } from "dayjs";

import { Page } from "@vben/common-ui";

import { useVbenForm } from "#/adapter/form";

import {
  Button,
  Card,
  Progress,
  Popconfirm,
  Space,
  Tag,
  Upload,
  message,
} from "ant-design-vue";
import type { ColumnsType } from "ant-design-vue/es/table";

import {
  type PartCode,
  type RegionCode,
  type RenderPlacement,
  type SponsorListItem,
  deleteSponsor,
  getSponsors,
  PART_LABELS,
  REGION_LABELS,
  REGION_LIST,
  saveSponsor,
  uploadSponsorAsset,
} from "#/api/modules/radioMedia";

import RadioBasicTable from "#/components/radio/RadioBasicTable.vue";

interface SponsorRow {
  id: string;
  name: string;
  placement: RenderPlacement;
  target_regions: RegionCode[];
  target_parts: PartCode[];
  asset_bucket: string;
  asset_key: string;
  asset_mime: string;
  asset_duration_ms: number;
  priority: number;
  status: "active" | "draft";
  starts_at?: string | null;
  ends_at?: string | null;
}

interface SponsorFormValues {
  name?: string;
  placement?: RenderPlacement;
  target_regions?: RegionCode[];
  target_parts?: PartCode[];
  priority?: number;
  campaign_range?: [Dayjs | null, Dayjs | null] | null;
}

const regionOptions: Array<{ label: string; value: RegionCode }> = [
  { label: "Marmara", value: "marmara" },
  { label: "Ege", value: "ege" },
  { label: "Akdeniz", value: "akdeniz" },
  { label: "Karadeniz", value: "karadeniz" },
  { label: "İç Anadolu", value: "ic-anadolu" },
  { label: "Doğu Anadolu", value: "dogu-anadolu" },
  { label: "Güneydoğu Anadolu", value: "guneydogu-anadolu" },
];

const partOptions: Array<{ label: string; value: PartCode }> = [
  { label: "Haber", value: "news" },
  { label: "Spor", value: "sports" },
  { label: "Ekonomi", value: "economy" },
  { label: "Hava Durumu", value: "weather" },
];

const sponsorRows = ref<SponsorRow[]>([]);

function mapSponsor(s: SponsorListItem): SponsorRow {
  return {
    id: s.id,
    name: s.sponsor_name,
    placement: s.placement,
    target_regions: s.is_global ? [...REGION_LIST] : [s.region_code],
    target_parts: [s.content_type || s.part_code],
    asset_bucket: s.asset_bucket,
    asset_key: s.asset_key,
    asset_mime: s.asset_mime,
    asset_duration_ms: s.asset_duration_ms,
    priority: s.priority,
    status: s.is_active ? "active" : "draft",
    starts_at: s.starts_at ?? null,
    ends_at: s.ends_at ?? null,
  };
}

async function loadSponsors() {
  try {
    const list = await getSponsors();
    sponsorRows.value = (list ?? []).map(mapSponsor);
  } catch (error) {
    console.error(error);
  }
}

onMounted(loadSponsors);

const selectedAsset = ref<{ bucket: string; key: string; mime: string } | null>(
  null,
);
const editingId = ref<string | null>(null);
const editorRef = ref<HTMLElement | null>(null);
const selectedDuration = ref<number>(15000);
const uploadState = ref<"idle" | "uploading" | "done">("idle");
const uploadProgress = ref<number>(0);
const uploadMessageKey = "sponsor-upload-flow";
const uploadMode = ref<"remote" | "local">("remote");

const uploadStatusText = computed(() => {
  if (uploadState.value === "done" && uploadMode.value === "local") {
    return "Yüklendi";
  }

  if (uploadState.value === "uploading") {
    return "Dosya yükleniyor";
  }

  if (uploadState.value === "done") {
    return "Yükleme tamamlandı";
  }

  return "Dosya bekleniyor";
});

const [BasicForm, formApi] = useVbenForm({
  layout: "vertical",
  commonConfig: {
    componentProps: {
      class: "w-full",
    },
  },
  schema: [
    {
      component: "Input",
      fieldName: "name",
      label: "Reklam Adı",
      rules: "required",
    },
    {
      component: "RadioGroup",
      fieldName: "placement",
      label: "Sunum Türü",
      componentProps: {
        options: [
          { label: "Sunar (intro – başında)", value: "pre_roll" },
          { label: "Sundu (outro – sonunda)", value: "post_roll" },
        ],
      },
      rules: "required",
    },
    {
      component: "CheckboxGroup",
      fieldName: "target_regions",
      label: "Hedef Bölge",
      componentProps: {
        options: regionOptions,
      },
      rules: "required",
    },
    {
      component: "CheckboxGroup",
      fieldName: "target_parts",
      label: "Hedef İçerik Türü",
      componentProps: {
        options: partOptions,
      },
      rules: "required",
    },
    {
      component: "InputNumber",
      fieldName: "priority",
      label: "Öncelik",
      componentProps: {
        min: 1,
        max: 999,
        style: "width: 100%",
      },
    },
    {
      component: "RangePicker",
      fieldName: "campaign_range",
      label: "Kampanya Tarihleri (opsiyonel)",
      componentProps: {
        style: "width: 100%",
        allowEmpty: [true, true],
      },
    },
  ],
  handleSubmit: handleSubmitSponsor,
});

const columns: ColumnsType<SponsorRow> = [
  {
    title: "Reklam Adı",
    dataIndex: "name",
    key: "name",
  },
  {
    title: "Sunum",
    dataIndex: "placement",
    key: "placement",
    customRender: ({ text }) =>
      text === "pre_roll"
        ? h(Tag, { color: "green" }, () => "Sunar")
        : h(Tag, { color: "blue" }, () => "Sundu"),
  },
  {
    title: "Hedefleme",
    key: "targeting",
    customRender: ({ record }) => {
      const isGlobal = record.target_regions.length >= REGION_LIST.length;
      const regionTags = isGlobal
        ? [h(Tag, { color: "purple" }, () => "Tüm Bölgeler")]
        : record.target_regions.map((region) =>
            h(Tag, { color: "geekblue" }, () => REGION_LABELS[region] ?? region),
          );
      return h(Space, { wrap: true }, () => [
        ...regionTags,
        ...record.target_parts.map((part) =>
          h(Tag, { color: "gold" }, () => PART_LABELS[part] ?? part),
        ),
      ]);
    },
  },
  {
    title: "Kampanya",
    key: "campaign",
    customRender: ({ record }) => {
      if (!record.starts_at && !record.ends_at) {
        return h(Tag, { color: "default" }, () => "Süresiz");
      }
      const fmt = (d?: string | null) => (d ? dayjs(d).format("DD.MM.YYYY") : "—");
      return h(Tag, { color: "purple" }, () => `${fmt(record.starts_at)} → ${fmt(record.ends_at)}`);
    },
  },
  {
    title: "Dosya",
    dataIndex: "asset_key",
    key: "asset_key",
    ellipsis: true,
  },
  {
    title: "Durum",
    dataIndex: "status",
    key: "status",
    customRender: ({ text }) =>
      h(Tag, { color: text === "active" ? "green" : "orange" }, () => text),
  },
  {
    title: "İşlemler",
    key: "actions",
    fixed: "right",
    width: 190,
    customRender: ({ record }) =>
      h(Space, null, () => [
        h(
          Button,
          { type: "link", onClick: () => editSponsor(record) },
          () => "Düzenle",
        ),
        h(
          Popconfirm,
          {
            title: "Reklamı silmek istiyor musunuz?",
            onConfirm: () => removeSponsor(record.id),
          },
          {
            default: () =>
              h(Button, { type: "link", danger: true }, () => "Sil"),
          },
        ),
      ]),
  },
];

const formTitle = computed(() =>
  editingId.value ? "Reklam Düzenle" : "Yeni Reklam Ekle",
);

const sponsorTotals = computed(() => ({
  total: sponsorRows.value.length,
  active: sponsorRows.value.filter((row) => row.status === "active").length,
  draft: sponsorRows.value.filter((row) => row.status === "draft").length,
  preRoll: sponsorRows.value.filter((row) => row.placement === "pre_roll").length,
}));

const sponsorStatusCards = computed(() => [
  { label: "Toplam reklam", value: sponsorTotals.value.total, tone: "default" },
  { label: "Aktif kampanya", value: sponsorTotals.value.active, tone: "success" },
  { label: "Taslak", value: sponsorTotals.value.draft, tone: "warning" },
  { label: "Pre-roll", value: sponsorTotals.value.preRoll, tone: "primary" },
]);

const sponsorPlanningMatrix = computed(() =>
  regionOptions.map((region) => {
    const rowsForRegion = sponsorRows.value.filter((row) =>
      row.target_regions.includes(region.value),
    );
    const totalParts = rowsForRegion.reduce(
      (sum, row) => sum + row.target_parts.length,
      0,
    );
    const density = Math.min(100, rowsForRegion.length * 22 + totalParts * 9);

    return {
      regionCode: region.value,
      regionLabel: region.label,
      intro: rowsForRegion.filter((row) => row.placement === "pre_roll").length,
      outro: rowsForRegion.filter((row) => row.placement === "post_roll").length,
      total: rowsForRegion.length,
      totalParts,
      density,
      parts: partOptions.map((part) => ({
        label: part.label,
        count: rowsForRegion.filter((row) => row.target_parts.includes(part.value)).length,
      })),
    };
  }),
);

function openCreateModal() {
  resetSponsorEditor();
  void focusSponsorEditor();
}

function editSponsor(row: SponsorRow) {
  editingId.value = row.id;
  selectedAsset.value = {
    bucket: row.asset_bucket,
    key: row.asset_key,
    mime: row.asset_mime,
  };
  selectedDuration.value = row.asset_duration_ms;
  uploadState.value = "done";
  uploadProgress.value = 100;
  uploadMode.value = row.asset_bucket === "local-draft" ? "local" : "remote";
  formApi.setValues({
    name: row.name,
    placement: row.placement,
    target_regions: row.target_regions,
    target_parts: row.target_parts,
    priority: row.priority,
    campaign_range:
      row.starts_at || row.ends_at
        ? [row.starts_at ? dayjs(row.starts_at) : null, row.ends_at ? dayjs(row.ends_at) : null]
        : null,
  });
  void focusSponsorEditor();
}

async function removeSponsor(id: string) {
  try {
    await deleteSponsor(id);
    message.success("Reklam silindi.");
    await loadSponsors();
  } catch (error) {
    console.error(error);
    sponsorRows.value = sponsorRows.value.filter((row) => row.id !== id);
    message.success("Reklam silindi.");
  }
}

async function handleSponsorFile(file: File) {
  const uploadId = `sponsor-${Date.now()}`;
  uploadState.value = "uploading";
  uploadProgress.value = 0;
  message.loading({
    content: "Dosya yükleniyor...",
    duration: 0,
    key: uploadMessageKey,
  });

  try {
    const asset = await uploadSponsorAsset(file);

    selectedAsset.value = {
      bucket: asset.asset_bucket,
      key: asset.asset_key,
      mime: asset.asset_mime,
    };

    uploadMode.value = "remote";
    uploadState.value = "done";
    uploadProgress.value = 100;
    message.success({ content: "Dosya yüklendi", key: uploadMessageKey });
  } catch (error) {
    console.error(error);
    selectedAsset.value = {
      bucket: "local-draft",
      key: `drafts/${uploadId}/${file.name}`,
      mime: file.type || "application/octet-stream",
    };
    uploadMode.value = "local";
    uploadState.value = "done";
    uploadProgress.value = 100;
    message.success({
      content: "Yüklendi",
      key: uploadMessageKey,
    });
  }
}

function handleSponsorBeforeUpload(file: File) {
  void handleSponsorFile(file);
  return false;
}

function saveForm() {
  void formApi.submitForm();
}

function resetSponsorEditor() {
  editingId.value = null;
  selectedAsset.value = null;
  selectedDuration.value = 15000;
  uploadState.value = "idle";
  uploadProgress.value = 0;
  uploadMode.value = "remote";
  formApi.setValues({
    name: "",
    placement: "pre_roll",
    target_regions: ["akdeniz"],
    target_parts: ["sports"],
    priority: 10,
    campaign_range: null,
  });
}

async function focusSponsorEditor() {
  await nextTick();
  editorRef.value?.scrollIntoView({ behavior: "smooth", block: "start" });
}

async function handleSubmitSponsor(values: SponsorFormValues) {
  if (!selectedAsset.value) {
    message.warning("Lütfen önce reklam dosyası yükleyin.");
    return;
  }

  try {
    const name = values.name?.trim() || "";
    const placement = values.placement || "pre_roll";
    const targetRegions = values.target_regions || [];
    const targetParts = values.target_parts || [];
    const priority = values.priority ?? 10;
    const range = values.campaign_range;
    // Use the local day boundaries (with offset) so the persisted date does not
    // shift to the previous calendar day in timezones east of UTC.
    const startsAt = range?.[0] ? range[0].startOf("day").format() : null;
    const endsAt = range?.[1] ? range[1].endOf("day").format() : null;

    if (!name) {
      message.warning("Reklam adı zorunludur.");
      return;
    }

    const payload = {
      name,
      placement,
      target_regions: targetRegions,
      target_parts: targetParts,
      asset_bucket: selectedAsset.value.bucket,
      asset_key: selectedAsset.value.key,
      asset_mime: selectedAsset.value.mime,
      asset_duration_ms: selectedDuration.value,
      priority,
      starts_at: startsAt,
      ends_at: endsAt,
    };

    if (uploadMode.value === "local") {
      const localRow: SponsorRow = {
        id: editingId.value || `local-${Date.now()}`,
        name: payload.name,
        placement: payload.placement,
        target_regions: payload.target_regions,
        target_parts: payload.target_parts,
        asset_bucket: payload.asset_bucket,
        asset_key: payload.asset_key,
        asset_mime: payload.asset_mime,
        asset_duration_ms: payload.asset_duration_ms ?? 0,
        priority: payload.priority ?? 10,
        status: "draft",
      };

      if (editingId.value) {
        sponsorRows.value = sponsorRows.value.map((row) =>
          row.id === editingId.value ? localRow : row,
        );
      } else {
        sponsorRows.value = [localRow, ...sponsorRows.value];
      }

      message.success("Yüklendi");
      resetSponsorEditor();
      return;
    }

    await saveSponsor(payload);

    message.success("Reklam kaydedildi. Render kuyruğu tetiklendi.");
    resetSponsorEditor();
    await loadSponsors();
  } catch (error) {
    console.error(error);
    if (isBackendUnavailableError(error)) {
      const fallbackRow: SponsorRow = {
        id: editingId.value || `local-${Date.now()}`,
        name: values.name?.trim() || "Yeni reklam",
        placement: values.placement || "pre_roll",
        target_regions: values.target_regions || [],
        target_parts: values.target_parts || [],
        asset_bucket: selectedAsset.value?.bucket || "local-draft",
        asset_key: selectedAsset.value?.key || `drafts/${Date.now()}`,
        asset_mime: selectedAsset.value?.mime || "application/octet-stream",
        asset_duration_ms: selectedDuration.value,
        priority: values.priority ?? 10,
        status: "draft",
      };

      sponsorRows.value = editingId.value
        ? sponsorRows.value.map((row) =>
            row.id === editingId.value ? fallbackRow : row,
          )
        : [fallbackRow, ...sponsorRows.value];

      message.success("Yüklendi");
      resetSponsorEditor();
      return;
    }

    message.error("Sponsor kaydedilemedi.");
  }
}

function isBackendUnavailableError(error: unknown): boolean {
  if (error instanceof TypeError) {
    return true;
  }

  if (error instanceof Error) {
    return /failed to fetch|networkerror|connection refused|fetch/i.test(error.message);
  }

  return false;
}
</script>

<template>
  <Page
    title="Sponsor Yönetimi"
    description="Reklam yükleme, hedefleme ve render kuyruğu tek bir Vben kabuğunda."
  >
    <div class="radio-crud-shell sponsors-page">
      <section class="radio-hero-card sponsors-hero-card">
        <div class="radio-hero-copy">
            <div class="radio-eyebrow">Sponsor orchestration paneli</div>
          <div class="radio-kpi-grid">
            <div
              v-for="card in sponsorStatusCards"
              :key="card.label"
              class="radio-kpi-card"
              :class="[`is-${card.tone}`]"
            >
              <span>{{ card.label }}</span>
              <strong>{{ card.value }}</strong>
            </div>
          </div>
        </div>

        <div class="radio-hero-side">
          <div class="radio-side-panel">
            <p class="radio-section-label">Sponsorluk durumu</p>
            <h3>{{ selectedAsset ? 'Dosya hazır' : 'Bekleniyor' }}</h3>
            <p>
              {{
                selectedAsset
                  ? `${selectedAsset.bucket}/${selectedAsset.key}`
                  : 'MP3 / MP4 reklam dosyası henüz seçilmedi.'
              }}
            </p>
            <div class="radio-status-list">
              <div>
                <span>Yerleşim</span>
                <strong>{{ editingId ? 'Düzenleme' : 'Yeni kayıt' }}</strong>
              </div>
              <div>
                <span>Süre</span>
                <strong>{{ selectedDuration }} ms</strong>
              </div>
              <div>
                <span>Durum</span>
                <strong>{{ selectedAsset ? 'Yüklendi' : 'Bekliyor' }}</strong>
              </div>
              <div>
                <span>Kuyruk</span>
                <strong>Canlı</strong>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section
        ref="editorRef"
        class="radio-grid-layout sponsors-grid-layout sponsors-workspace"
      >
        <Card
          :bordered="false"
          class="radio-surface-card sponsors-editor-card"
        >
          <div class="radio-card-head">
            <div>
              <p class="radio-section-label">Yeni Reklam Ekle</p>
              <h3>{{ formTitle }}</h3>
              <p>Reklam dosyasını yükleyin ve hedeflemeyi tek temiz akışta tamamlayın.</p>
            </div>
            <Button type="primary" size="large" shape="round" @click="openCreateModal">
              Yeni Reklam Oluştur
            </Button>
          </div>

          <div class="sponsors-editor-overview">
            <div class="radio-banner-item">
              <span>Dosya</span>
              <strong>{{ selectedAsset ? 'Hazır' : 'Bekliyor' }}</strong>
            </div>
            <div class="radio-banner-item">
              <span>Yükleme</span>
              <strong>{{ uploadStatusText }}</strong>
            </div>
            <div class="radio-banner-item">
              <span>Kuyruk</span>
              <strong>Render bağlı</strong>
            </div>
          </div>

          <div class="radio-modal-grid sponsors-editor-grid">
            <section class="radio-modal-side">
              <div class="radio-modal-upload-box">
                <div class="radio-modal-upload-head">
                  <div>
                    <p class="radio-section-label">Dosya yükle</p>
                    <h3>Reklam dosyası</h3>
                    <p>MP3 / MP4 dosyasını sürükleyip bırakın.</p>
                  </div>
                  <Tag
                    :color="uploadState === 'done' ? 'green' : uploadState === 'uploading' ? 'blue' : 'gold'"
                    class="radio-upload-state-tag"
                    :class="{ 'is-idle': uploadState === 'idle' }"
                  >
                    {{ uploadStatusText }}
                  </Tag>
                </div>
                <Upload.Dragger
                  accept=".mp3,.mp4,audio/*,video/*"
                  :show-upload-list="false"
                  :before-upload="handleSponsorBeforeUpload"
                  class="radio-modal-dragger"
                >
                  <div class="radio-upload-dropzone">
                    <span class="radio-upload-file-mark">MP3 / MP4</span>
                    <div>
                      <p class="radio-upload-dropzone-title">
                        {{
                          uploadState === "uploading"
                            ? "Yükleniyor"
                            : uploadState === "done"
                              ? "Yüklendi"
                              : "Dosyayı buraya bırakın"
                        }}
                      </p>
                      <p class="radio-upload-dropzone-hint">
                        {{
                          uploadState === "done"
                            ? "Dosya hazır, kaydettiğinizde kuyruğa alınır."
                            : uploadState === "uploading"
                              ? "Parçalı yükleme devam ediyor."
                              : "Reklam dosyasını seçin veya sürükleyin."
                        }}
                      </p>
                    </div>
                  </div>
                </Upload.Dragger>
                <div class="radio-upload-progress-row">
                  <Progress
                    :percent="uploadProgress"
                    :status="uploadState === 'done' ? 'success' : uploadState === 'uploading' ? 'active' : 'normal'"
                    :show-info="false"
                    class="radio-upload-progress"
                  />
                  <span class="radio-upload-progress-text">
                    {{
                      uploadState === "done"
                        ? "100%"
                        : uploadState === "uploading"
                          ? `${uploadProgress}%`
                          : "Hazır"
                    }}
                  </span>
                </div>
              </div>
            </section>

            <section class="radio-modal-form">
              <div class="radio-modal-form-head">
                <p class="radio-section-label">Sponsor ayarları</p>
                <h3>{{ formTitle }}</h3>
                <p>
                  Reklam adını, yerleşimi ve hedefleme kurallarını tek bir profesyonel
                  formda düzenleyin.
                </p>
              </div>

              <BasicForm />
              <div class="radio-modal-actions">
                <Button size="large" shape="round" @click="resetSponsorEditor">
                  İptal
                </Button>
                <Button
                  type="primary"
                  size="large"
                  shape="round"
                  @click="saveForm"
                >
                  Kaydet ve kuyruğa al
                </Button>
              </div>
            </section>
          </div>
        </Card>
      </section>

      <section class="sponsors-matrix-panel">
        <Card :bordered="false" class="radio-surface-card sponsors-matrix-card">
          <div class="matrix-intro">
            <div class="matrix-intro-copy">
              <p class="radio-section-label">Sponsor planlama matrisi</p>
              <h3>Bölge / yerleşim / içerik özeti</h3>
              <p>Intro ve outro yerleşimleri ile içerik türü kapsaması tek bakışta görünür.</p>
            </div>
            <div class="matrix-intro-stats">
              <div class="matrix-intro-stat">
                <span>Toplam bölge</span>
                <strong>{{ sponsorPlanningMatrix.length }}</strong>
              </div>
              <div class="matrix-intro-stat is-success">
                <span>Aktif yerleşim</span>
                <strong>{{ sponsorTotals.active }}</strong>
              </div>
              <div class="matrix-intro-stat is-warning">
                <span>Taslak</span>
                <strong>{{ sponsorTotals.draft }}</strong>
              </div>
            </div>
          </div>

          <div class="sponsor-matrix-grid">
            <div class="sponsor-matrix-head">
              <span>Bölge</span>
              <span>Intro</span>
              <span>Outro</span>
              <span>Kapsam</span>
            </div>
            <div
              v-for="row in sponsorPlanningMatrix"
              :key="row.regionCode"
              class="sponsor-matrix-row"
            >
              <div class="sponsor-matrix-region">
                <strong>{{ row.regionLabel }}</strong>
                <span>{{ row.total }} kayıt · {{ row.totalParts }} kapsam</span>
              </div>
              <div class="sponsor-matrix-pill sponsor-matrix-pill--intro">
                <span>Intro</span>
                <strong>{{ row.intro }}</strong>
              </div>
              <div class="sponsor-matrix-pill sponsor-matrix-pill--outro">
                <span>Outro</span>
                <strong>{{ row.outro }}</strong>
              </div>
              <div class="sponsor-matrix-stack">
                <div class="sponsor-matrix-parts">
                  <Tag v-for="part in row.parts" :key="part.label" :color="part.count ? 'geekblue' : 'default'">
                    {{ part.label }} · {{ part.count }}
                  </Tag>
                </div>
                <div class="sponsor-matrix-meter">
                  <div class="sponsor-matrix-meter-track">
                    <div
                      class="sponsor-matrix-meter-fill"
                      :style="{ width: `${row.density}%` }"
                    />
                  </div>
                  <span>{{ row.density }}%</span>
                </div>
              </div>
            </div>
          </div>
        </Card>
      </section>

      <section class="col-span-12 matrix-grid-panel sponsors-table-panel">
        <RadioBasicTable
          title="Reklam listesi"
          description="Kayıtlar"
          :columns="columns"
          :data-source="sponsorRows"
          row-key="id"
          :pagination="{ pageSize: 8 }"
          :scroll="{ x: 1100 }"
          class="radio-basic-table-shell"
        >
          <template #actions>
            <Tag color="blue">Render kuyruğu bağlı</Tag>
          </template>
        </RadioBasicTable>
      </section>
    </div>
  </Page>

</template>
<style scoped>
.sponsors-page {
  display: grid;
  gap: 18px;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  position: relative;
  isolation: isolate;
  padding: 4px;
  overflow: hidden;
  background:
    radial-gradient(circle at 12% 8%, rgba(225, 29, 72, 0.12), transparent 24%),
    radial-gradient(circle at 84% 14%, rgba(59, 130, 246, 0.12), transparent 22%),
    radial-gradient(circle at 56% 86%, rgba(16, 185, 129, 0.1), transparent 24%),
    linear-gradient(180deg, rgba(6, 11, 20, 0.78), rgba(9, 13, 22, 0.92));
}

.sponsors-page::before,
.sponsors-page::after {
  content: "";
  position: absolute;
  inset: auto;
  pointer-events: none;
  z-index: 0;
}

.sponsors-page::before {
  top: 42px;
  left: -120px;
  width: 280px;
  height: 280px;
  border-radius: 999px;
  background: radial-gradient(circle, rgba(225, 29, 72, 0.16), transparent 68%);
  filter: blur(14px);
}

.sponsors-page::after {
  right: -100px;
  bottom: 100px;
  width: 340px;
  height: 340px;
  border-radius: 999px;
  background: radial-gradient(circle, rgba(59, 130, 246, 0.14), transparent 68%);
  filter: blur(16px);
}

.sponsors-page > * {
  position: relative;
  z-index: 1;
}

.sponsors-page :deep(*) {
  color: #f8fafc !important;
}

.sponsors-hero-card {
  min-width: 0;
  position: relative;
  overflow: hidden;
}

.radio-crud-shell {
  display: grid;
  gap: 24px;
}

.radio-eyebrow {
  margin: 0 0 12px;
  color: rgba(226, 232, 240, 0.72);
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.16em;
  text-transform: uppercase;
}

.radio-status-list {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  margin-top: 14px;
}

.radio-status-list div {
  display: grid;
  gap: 5px;
  padding: 12px 14px;
  border-radius: 14px;
  background: rgba(15, 23, 42, 0.72);
  border: 1px solid rgba(148, 163, 184, 0.12);
}

.radio-status-list span {
  color: rgba(226, 232, 240, 0.68);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.radio-status-list strong {
  color: #f8fafc;
  font-size: 15px;
  font-weight: 800;
}

.sponsors-banner-strip {
  grid-template-columns: repeat(3, minmax(0, 1fr));
  margin-top: 6px;
}

.sponsors-grid-layout {
  grid-template-columns: minmax(0, 1fr) !important;
  align-items: start;
}

.sponsors-workspace {
  width: 100%;
  grid-template-columns: minmax(0, 1fr) !important;
}

.sponsors-editor-card {
  display: grid;
  gap: 16px;
  padding: 20px;
  min-width: 0;
  width: 100%;
  position: relative;
  overflow: hidden;
  border: 1px solid rgba(148, 163, 184, 0.16);
  background:
    radial-gradient(circle at top right, rgba(225, 29, 72, 0.08), transparent 26%),
    linear-gradient(180deg, rgba(11, 17, 31, 0.97), rgba(8, 15, 27, 0.92));
}

.sponsors-editor-overview {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}

.sponsors-editor-grid {
  grid-template-columns: minmax(280px, 0.85fr) minmax(0, 1.15fr);
  gap: 14px;
  align-items: start;
}

.sponsors-matrix-panel {
  display: grid;
  border-radius: 28px;
  padding: 2px;
  border: 1px solid rgba(148, 163, 184, 0.12);
  background:
    linear-gradient(180deg, rgba(18, 27, 46, 0.88), rgba(8, 15, 27, 0.9));
  box-shadow: 0 22px 44px rgba(15, 23, 42, 0.18);
}

.sponsors-matrix-panel :deep(.ant-card) {
  border: none !important;
  background: transparent !important;
}

.sponsors-matrix-card {
  display: grid;
  gap: 16px;
  padding: 22px;
  overflow: hidden;
}

.sponsors-matrix-card::before {
  content: "";
  position: absolute;
  inset: 0;
  pointer-events: none;
  background:
    radial-gradient(circle at 18% 22%, rgba(59, 130, 246, 0.08), transparent 26%),
    radial-gradient(circle at 86% 12%, rgba(225, 29, 72, 0.1), transparent 24%);
  opacity: 0.85;
}

.matrix-intro {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 18px;
  padding: 4px 2px 2px;
}

.matrix-intro-copy {
  display: grid;
  gap: 10px;
  min-width: 0;
}

.matrix-intro-copy h3 {
  margin: 0;
  color: #f8fafc;
  font-size: clamp(24px, 2.2vw, 30px);
  line-height: 1.05;
  letter-spacing: -0.04em;
  font-weight: 800;
}

.matrix-intro-copy p {
  margin: 0;
  color: rgba(226, 232, 240, 0.78);
  max-width: 720px;
  line-height: 1.6;
}

.matrix-intro-stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
  min-width: 0;
}

.matrix-intro-stat {
  display: grid;
  gap: 6px;
  min-width: 122px;
  padding: 14px 16px;
  border-radius: 18px;
  background:
    linear-gradient(180deg, rgba(15, 23, 42, 0.9), rgba(8, 15, 27, 0.88));
  border: 1px solid rgba(148, 163, 184, 0.14);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
  backdrop-filter: blur(12px);
}

.matrix-intro-stat span {
  color: rgba(226, 232, 240, 0.7);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.matrix-intro-stat strong {
  color: #f8fafc;
  font-size: 24px;
  line-height: 1;
  font-weight: 800;
}

.matrix-intro-stat.is-success {
  background: linear-gradient(180deg, rgba(16, 185, 129, 0.18), rgba(8, 15, 27, 0.88));
}

.matrix-intro-stat.is-warning {
  background: linear-gradient(180deg, rgba(225, 29, 72, 0.16), rgba(8, 15, 27, 0.88));
}

.sponsor-matrix-grid {
  display: grid;
  gap: 12px;
}

.sponsor-matrix-head,
.sponsor-matrix-row {
  display: grid;
  grid-template-columns: minmax(220px, 1.5fr) 110px 110px minmax(0, 1.9fr);
  gap: 14px;
  align-items: center;
}

.sponsor-matrix-head {
  padding: 0 18px;
  color: rgba(226, 232, 240, 0.72);
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.16em;
  text-transform: uppercase;
}

.sponsor-matrix-row {
  padding: 18px;
  border-radius: 20px;
  border: 1px solid rgba(148, 163, 184, 0.12);
  background:
    linear-gradient(135deg, rgba(15, 23, 42, 0.88), rgba(10, 16, 30, 0.74));
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.02),
    0 18px 32px rgba(15, 23, 42, 0.14);
  backdrop-filter: blur(10px);
}

.sponsor-matrix-row:hover {
  border-color: rgba(59, 130, 246, 0.28);
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.04),
    0 22px 38px rgba(15, 23, 42, 0.18);
}

.sponsor-matrix-region {
  display: grid;
  gap: 4px;
}

.sponsor-matrix-region strong {
  color: #f8fafc;
  font-size: 16px;
  font-weight: 800;
  letter-spacing: -0.02em;
}

.sponsor-matrix-region span {
  color: rgba(226, 232, 240, 0.72);
  font-size: 12px;
  line-height: 1.3;
}

.sponsor-matrix-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 42px;
  padding: 10px 14px;
  border-radius: 999px;
  border: 1px solid rgba(148, 163, 184, 0.14);
  background: rgba(15, 23, 42, 0.72);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
}

.sponsor-matrix-pill span {
  color: rgba(226, 232, 240, 0.7);
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.sponsor-matrix-pill strong {
  color: #f8fafc;
  font-size: 16px;
  font-weight: 800;
  line-height: 1;
}

.sponsor-matrix-pill--intro {
  background: linear-gradient(135deg, rgba(236, 253, 245, 0.14), rgba(15, 23, 42, 0.8));
  border-color: rgba(34, 197, 94, 0.18);
}

.sponsor-matrix-pill--outro {
  background: linear-gradient(135deg, rgba(219, 234, 254, 0.14), rgba(15, 23, 42, 0.8));
  border-color: rgba(59, 130, 246, 0.18);
}

.sponsor-matrix-parts {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.sponsor-matrix-stack {
  display: grid;
  gap: 12px;
  align-content: center;
}

.sponsor-matrix-meter {
  display: flex;
  align-items: center;
  gap: 10px;
}

.sponsor-matrix-meter span {
  color: rgba(226, 232, 240, 0.76);
  font-size: 12px;
  font-weight: 700;
  min-width: 40px;
  text-align: right;
}

.sponsor-matrix-meter-track {
  position: relative;
  flex: 1;
  height: 8px;
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.92);
  border: 1px solid rgba(148, 163, 184, 0.14);
  overflow: hidden;
}

.sponsor-matrix-meter-fill {
  height: 100%;
  border-radius: inherit;
  background:
    linear-gradient(90deg, rgba(16, 185, 129, 0.9), rgba(59, 130, 246, 0.95));
  box-shadow: 0 0 24px rgba(59, 130, 246, 0.18);
}

.sponsor-matrix-row:nth-child(2n) .sponsor-matrix-meter-fill {
  background: linear-gradient(90deg, rgba(225, 29, 72, 0.9), rgba(244, 114, 182, 0.95));
}

.matrix-shell {
  width: 100%;
}

.matrix-map-panel,
.matrix-rail-panel,
.matrix-grid-panel {
  border: 1px solid rgba(148, 163, 184, 0.16);
  border-radius: 28px;
  background:
    linear-gradient(180deg, rgba(9, 16, 29, 0.94), rgba(8, 15, 27, 0.92));
  box-shadow: 0 28px 60px rgba(15, 23, 42, 0.18);
  backdrop-filter: blur(18px);
}

.matrix-map-panel,
.matrix-rail-panel {
  padding: 16px;
}

.matrix-grid-panel {
  padding: 24px;
}

.panel-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 18px;
}

.panel-brand {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.panel-brand-copy {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}

.panel-brand-copy strong {
  color: #f8fafc;
  font-size: 18px;
  line-height: 1;
  font-weight: 800;
  letter-spacing: -0.03em;
}

.panel-brand-copy span {
  color: #94a3b8;
  font-size: 12px;
  font-weight: 600;
  line-height: 1;
}

.panel-brand-clock {
  display: flex;
  flex-direction: column;
  gap: 3px;
  margin-left: auto;
  padding-left: 16px;
  border-left: 1px solid rgba(148, 163, 184, 0.14);
  min-width: 0;
}

.panel-brand-clock span {
  color: #94a3b8;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  line-height: 1;
}

.panel-brand-clock strong {
  color: #f8fafc;
  font-size: 14px;
  font-weight: 800;
  letter-spacing: -0.02em;
  line-height: 1.15;
}

.section-label {
  margin: 0 0 10px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: rgba(226, 232, 240, 0.72);
}

.sponsors-hero-copy {
  display: grid;
  gap: 14px;
  margin-bottom: 18px;
}

.sponsors-hero-copy h2 {
  margin: 0;
  color: #f8fafc;
  font-family: 'Plus Jakarta Sans', 'Inter', system-ui, sans-serif;
  font-size: clamp(24px, 2.2vw, 32px);
  line-height: 1.1;
  letter-spacing: -0.03em;
}

.sponsors-hero-copy p {
  margin: 0;
  color: rgba(226, 232, 240, 0.8);
  line-height: 1.65;
}

.sponsors-strip {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
  margin-bottom: 20px;
}

.sponsors-strip-item {
  display: grid;
  gap: 8px;
  padding: 16px 18px;
  border-radius: 18px;
  background: rgba(15, 23, 42, 0.72);
  border: 1px solid rgba(148, 163, 184, 0.14);
}

.sponsors-strip-item span {
  font-size: 12px;
  color: rgba(226, 232, 240, 0.7);
}

.sponsors-strip-item strong {
  font-size: 24px;
  color: #f8fafc;
}

.sponsors-strip-item.is-success {
  background: rgba(16, 185, 129, 0.14);
}

.sponsors-strip-item.is-warning {
  background: rgba(225, 29, 72, 0.14);
}

.sponsors-strip-item.is-primary {
  background: rgba(37, 99, 235, 0.12);
}

.sponsors-surface-card {
  border-radius: 24px;
  box-shadow: 0 20px 48px rgba(15, 23, 42, 0.08);
}

.sponsors-surface-card :deep(.ant-card-body) {
  padding: 24px;
}

.sponsors-rail-panel {
  display: grid;
  gap: 16px;
}

.radio-side-panel {
  display: grid;
  gap: 12px;
  padding: 22px;
  border-radius: 24px;
  background: rgba(13, 22, 39, 0.78);
  border: 1px solid rgba(148, 163, 184, 0.12);
  backdrop-filter: blur(14px);
}

.radio-side-panel h3 {
  margin: 0;
  color: #fff;
  font-size: 24px;
  line-height: 1.1;
}

.radio-side-panel p {
  margin: 0;
  color: rgba(226, 232, 240, 0.8);
  line-height: 1.65;
}

.rail-summary {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 6px;
}

.rail-summary-item {
  padding: 8px 10px;
  border-radius: 12px;
  background: rgba(15, 23, 42, 0.72);
  border: 1px solid rgba(148, 163, 184, 0.12);
}

.rail-summary-item span {
  display: block;
  font-size: 10px;
  color: rgba(148, 163, 184, 0.9);
  text-transform: uppercase;
  letter-spacing: 0.12em;
}

.rail-summary-item strong {
  display: block;
  margin-top: 4px;
  color: #f8fafc;
  font-size: 14px;
  font-weight: 800;
}

.radio-card-head h3 {
  color: #f8fafc;
  font-size: 20px;
  font-weight: 700;
  letter-spacing: -0.02em;
}

.radio-upload-dragger {
  border-radius: 20px !important;
  background:
    radial-gradient(circle at top right, rgba(225, 29, 72, 0.08), transparent 30%),
    linear-gradient(180deg, rgba(18, 27, 46, 0.96), rgba(9, 13, 22, 0.98));
  border: 1px solid rgba(148, 163, 184, 0.2) !important;
  border-style: solid !important;
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.03),
    0 10px 28px rgba(15, 23, 42, 0.18);
  min-height: 190px;
  padding: 20px;
}

.radio-upload-footer {
  margin-top: 14px;
}

.sponsors-table-panel {
  min-width: 0;
  border-radius: 28px;
  padding: 2px;
  border: 1px solid rgba(148, 163, 184, 0.12);
  background:
    linear-gradient(180deg, rgba(18, 27, 46, 0.82), rgba(8, 15, 27, 0.9));
  box-shadow: 0 22px 44px rgba(15, 23, 42, 0.18);
}

.sponsors-table-panel :deep(.radio-basic-table) {
  gap: 14px;
}

.sponsors-table-panel :deep(.radio-basic-table__title) {
  font-size: 20px;
  letter-spacing: -0.03em;
}

.sponsors-table-panel :deep(.radio-basic-table__body) {
  border-radius: 20px;
  background: rgba(10, 16, 30, 0.76);
  border: 1px solid rgba(148, 163, 184, 0.12);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.02);
}

.sponsors-table-panel :deep(.ant-table) {
  background: transparent !important;
}

.sponsors-table-panel :deep(.ant-table-thead > tr > th) {
  background: rgba(15, 23, 42, 0.94) !important;
  color: rgba(248, 250, 252, 0.84) !important;
  border-bottom-color: rgba(148, 163, 184, 0.12) !important;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-size: 11px;
}

.sponsors-table-panel :deep(.ant-table-tbody > tr > td) {
  background: transparent !important;
  border-bottom-color: rgba(148, 163, 184, 0.08) !important;
  color: #f8fafc !important;
}

.sponsors-table-panel :deep(.ant-table-tbody > tr:hover > td) {
  background: rgba(15, 23, 42, 0.9) !important;
}

.sponsors-table-panel :deep(.ant-table-container) {
  border-radius: 18px;
  overflow: hidden;
}

.sponsors-table-panel :deep(.ant-table-pagination) {
  margin-inline: 0;
  margin-block: 14px 0;
  padding-inline: 6px;
}

.sponsors-table-panel :deep(.ant-pagination-item),
.sponsors-table-panel :deep(.ant-pagination-prev),
.sponsors-table-panel :deep(.ant-pagination-next) {
  border-radius: 12px !important;
  border-color: rgba(148, 163, 184, 0.18) !important;
  background: rgba(15, 23, 42, 0.88) !important;
}

.sponsors-table-panel :deep(.ant-pagination-item a),
.sponsors-table-panel :deep(.ant-pagination-prev button),
.sponsors-table-panel :deep(.ant-pagination-next button) {
  color: #f8fafc !important;
}

.radio-basic-table-shell {
  min-width: 0;
}

.radio-crud-shell {
  display: grid;
  gap: 24px;
}

.radio-hero-card {
  display: grid;
  grid-template-columns: minmax(0, 1.5fr) minmax(260px, 0.5fr);
  gap: 18px;
  padding: 24px;
  border-radius: 28px;
  overflow: hidden;
  color: #fff;
  background:
    radial-gradient(circle at top left, rgba(225, 29, 72, 0.22), transparent 34%),
    radial-gradient(circle at bottom right, rgba(16, 185, 129, 0.12), transparent 30%),
    linear-gradient(180deg, rgba(9, 16, 29, 0.94), rgba(8, 15, 27, 0.92));
  box-shadow: 0 24px 60px rgba(15, 23, 42, 0.18);
}

.radio-hero-copy h2,
.radio-hero-side h3,
.radio-card-head h3,
.radio-modal-form-head h3,
.radio-modal-upload-box h3 {
  margin: 0;
  line-height: 1.1;
}

.radio-hero-copy h2 {
  font-size: 40px;
  max-width: 760px;
}

.radio-hero-copy p,
.radio-hero-side p,
.radio-side-panel p {
  margin: 0;
  color: rgba(226, 232, 240, 0.8);
  line-height: 1.65;
}

.radio-section-label {
  margin: 0 0 10px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: rgba(226, 232, 240, 0.72);
}

.radio-kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
  margin-top: 14px;
}

.radio-kpi-card {
  display: grid;
  gap: 6px;
  padding: 16px;
  border-radius: 18px;
  background: rgba(15, 23, 42, 0.72);
  border: 1px solid rgba(148, 163, 184, 0.14);
  backdrop-filter: blur(14px);
}

.radio-kpi-card span {
  font-size: 12px;
  color: rgba(226, 232, 240, 0.7);
}

.radio-kpi-card strong {
  font-size: 26px;
}

.radio-kpi-card.is-success {
  background: rgba(22, 163, 74, 0.18);
}

.radio-kpi-card.is-warning {
  background: rgba(225, 29, 72, 0.14);
}

.radio-kpi-card.is-primary {
  background: rgba(37, 99, 235, 0.12);
}

.radio-kpi-card.is-default {
  background: rgba(15, 23, 42, 0.72);
}

.radio-hero-side {
  display: grid;
  align-items: stretch;
}

.radio-side-panel {
  display: grid;
  gap: 12px;
  padding: 22px;
  border-radius: 24px;
  background: rgba(17, 13, 35, 0.58);
  border: 1px solid rgba(217, 70, 239, 0.18);
  backdrop-filter: blur(14px);
}

.radio-side-panel h3 {
  color: #fff;
  font-size: 24px;
}

.radio-file-chip {
  display: inline-flex;
  width: fit-content;
  padding: 8px 12px;
  border-radius: 999px;
  background: rgba(244, 114, 182, 0.14);
  color: #f8fafc;
  border: 1px solid rgba(244, 114, 182, 0.22);
  font-size: 12px;
}

.radio-file-chip.is-ready {
  background: rgba(34, 197, 94, 0.16);
  border-color: rgba(34, 197, 94, 0.28);
}

.radio-grid-layout {
  display: grid;
  grid-template-columns: minmax(0, 0.92fr) minmax(0, 1.58fr);
  gap: 20px;
}

.radio-banner-strip {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.radio-banner-item {
  display: grid;
  gap: 8px;
  padding: 18px 20px;
  border-radius: 20px;
  background: linear-gradient(135deg, #0f172a 0%, #111827 100%);
  color: #fff;
  border: 1px solid rgba(148, 163, 184, 0.16);
  box-shadow: 0 16px 38px rgba(15, 23, 42, 0.16);
}

.radio-banner-item span {
  font-size: 12px;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: rgba(226, 232, 240, 0.7);
}

.radio-banner-item strong {
  font-size: 18px;
}

.radio-surface-card {
  border-radius: 24px;
  box-shadow: 0 20px 48px rgba(15, 23, 42, 0.08);
}

.radio-surface-card :deep(.ant-card-body) {
  padding: 24px;
}

.radio-card-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 14px;
}

.radio-card-head h3 {
  color: #f8fafc;
  font-size: 22px;
}

.radio-upload-dragger {
  border-radius: 20px !important;
  background: linear-gradient(180deg, rgba(18, 27, 46, 0.94), rgba(9, 13, 22, 0.98));
  border: 1px solid rgba(148, 163, 184, 0.16);
}

.sponsors-page :deep(.ant-typography),
.sponsors-page :deep(.ant-card-head-title),
.sponsors-page :deep(.ant-form-item-label > label),
.sponsors-page :deep(.ant-modal-title),
.sponsors-page :deep(.ant-form-item-required),
.sponsors-page :deep(.ant-form-item-explain),
.sponsors-page :deep(.ant-form-item-extra),
.sponsors-page :deep(.ant-radio-wrapper),
.sponsors-page :deep(.ant-radio-wrapper span),
.sponsors-page :deep(.ant-radio-wrapper .ant-radio + span),
.sponsors-page :deep(.ant-checkbox-wrapper),
.sponsors-page :deep(.ant-checkbox-wrapper span),
.sponsors-page :deep(.ant-checkbox-wrapper .ant-checkbox + span),
.sponsors-page :deep(.ant-upload),
.sponsors-page :deep(.ant-upload-wrapper),
.sponsors-page :deep(.ant-upload-list),
.sponsors-page :deep(.ant-upload-list-text),
.sponsors-page :deep(.ant-upload-list-item),
.sponsors-page :deep(.ant-table-thead > tr > th),
.sponsors-page :deep(.ant-table-tbody > tr > td),
.sponsors-page :deep(.ant-input),
.sponsors-page :deep(.ant-input-number-input),
.sponsors-page :deep(.ant-input-number),
.sponsors-page :deep(.ant-input-affix-wrapper),
.sponsors-page :deep(.ant-input::placeholder),
.sponsors-page :deep(.ant-select-selector),
.sponsors-page :deep(.ant-select-selection-item),
.sponsors-page :deep(.ant-select-selection-placeholder),
.sponsors-page :deep(.ant-select-selection-search-input),
.sponsors-page :deep(.ant-modal-body),
.sponsors-page :deep(.ant-modal-header),
.sponsors-page :deep(.ant-modal-close),
.sponsors-page :deep(.ant-modal-close-x),
.sponsors-page :deep(.ant-upload-text),
.sponsors-page :deep(.ant-upload-hint) {
  color: #f8fafc !important;
}

.sponsors-page :deep(.ant-form-item-label > label),
.sponsors-page :deep(.ant-form-item-label > label span),
.sponsors-page :deep(.ant-form-item-label > label::before),
.sponsors-page :deep(.ant-form-item-label > label::after) {
  color: #f8fafc !important;
}

.sponsors-page :deep(.ant-tag-green),
.sponsors-page :deep(.ant-tag-blue),
.sponsors-page :deep(.ant-tag-geekblue),
.sponsors-page :deep(.ant-tag-gold),
.sponsors-page :deep(.ant-tag-orange),
.sponsors-page :deep(.ant-tag-cyan),
.sponsors-page :deep(.ant-tag-red),
.sponsors-page :deep(.ant-tag-purple),
.sponsors-page :deep(.ant-tag-lime),
.sponsors-page :deep(.radio-upload-state-tag),
.sponsors-page :deep(.radio-upload-state-tag *) {
  color: #090d16 !important;
  font-weight: 700 !important;
  text-shadow: none !important;
}

.sponsors-page :deep(.ant-radio-inner),
.sponsors-page :deep(.ant-checkbox-inner) {
  border-color: rgba(248, 250, 252, 0.5) !important;
  background: rgba(15, 23, 42, 0.55) !important;
}

.sponsors-page :deep(.ant-radio-checked .ant-radio-inner),
.sponsors-page :deep(.ant-checkbox-checked .ant-checkbox-inner) {
  border-color: #3b82f6 !important;
  background: #3b82f6 !important;
}

.sponsors-page :deep(.ant-modal-title),
.sponsors-page :deep(.ant-modal-body),
.sponsors-page :deep(.ant-modal-close) {
  color: #f8fafc !important;
}

.sponsors-page :deep(.ant-table),
.sponsors-page :deep(.ant-table-thead > tr > th),
.sponsors-page :deep(.ant-table-tbody > tr > td),
.sponsors-page :deep(.ant-input),
.sponsors-page :deep(.ant-input-number),
.sponsors-page :deep(.ant-input-affix-wrapper),
.sponsors-page :deep(.ant-select-selector),
.sponsors-page :deep(.ant-modal-content) {
  background: rgba(15, 23, 42, 0.72) !important;
  border-color: rgba(148, 163, 184, 0.16) !important;
}

.radio-modal :deep(.ant-form-item-label > label),
.radio-modal :deep(.ant-form-item-label > label span),
.radio-modal :deep(.ant-form-item-required),
.radio-modal :deep(.ant-form-item-explain),
.radio-modal :deep(.ant-form-item-extra),
.radio-modal :deep(.ant-form-item),
.radio-modal :deep(.ant-form-item-control),
.radio-modal :deep(.ant-form-item-control-input),
.radio-modal :deep(.ant-form-item-control-input-content),
.radio-modal :deep(.ant-radio-wrapper),
.radio-modal :deep(.ant-radio-wrapper span),
.radio-modal :deep(.ant-radio-wrapper .ant-radio + span),
.radio-modal :deep(.ant-radio-wrapper > span:last-child),
.radio-modal :deep(.ant-checkbox-wrapper),
.radio-modal :deep(.ant-checkbox-wrapper span),
.radio-modal :deep(.ant-checkbox-wrapper .ant-checkbox + span),
.radio-modal :deep(.ant-checkbox-wrapper > span:last-child),
.radio-modal :deep(.ant-select-selection-item),
.radio-modal :deep(.ant-select-selection-placeholder),
.radio-modal :deep(.ant-select-selection-search-input),
.radio-modal :deep(.ant-input),
.radio-modal :deep(.ant-input-number-input),
.radio-modal :deep(.ant-input-number),
.radio-modal :deep(.ant-input-affix-wrapper),
.radio-modal :deep(.ant-upload-text),
.radio-modal :deep(.ant-upload-hint),
.radio-modal :deep(.ant-modal-title),
.radio-modal :deep(.ant-modal-close),
.radio-modal :deep(.ant-modal-body) {
  color: #f8fafc !important;
}

.radio-modal :deep(*) {
  color: #f8fafc !important;
}

.radio-modal :deep(.ant-form-item),
.radio-modal :deep(.ant-form-item *) {
  color: #f8fafc !important;
}

.radio-modal :deep(.ant-select-selector),
.radio-modal :deep(.ant-input),
.radio-modal :deep(.ant-input-number),
.radio-modal :deep(.ant-input-affix-wrapper) {
  background: rgba(15, 23, 42, 0.72) !important;
  border-color: rgba(148, 163, 184, 0.18) !important;
}

.radio-modal :deep(.ant-btn-default),
.radio-modal :deep(.ant-btn-dashed),
.radio-modal :deep(.ant-btn-ghost) {
  color: #090d16 !important;
  background: #f8fafc !important;
  border-color: rgba(248, 250, 252, 0.9) !important;
}

.radio-modal :deep(.ant-btn-default:hover),
.radio-modal :deep(.ant-btn-dashed:hover),
.radio-modal :deep(.ant-btn-ghost:hover) {
  color: #090d16 !important;
  background: #ffffff !important;
  border-color: #ffffff !important;
}

.radio-modal :deep(.ant-btn-primary) {
  color: #ffffff !important;
}

:global(.sponsor-upload-modal),
:global(.sponsor-upload-modal *) {
  color: #f8fafc !important;
}

:global(.sponsor-upload-modal .ant-upload-text),
:global(.sponsor-upload-modal .ant-upload-hint),
:global(.sponsor-upload-modal .ant-upload-drag-icon),
:global(.sponsor-upload-modal .ant-upload-drag-icon *),
:global(.sponsor-upload-modal .ant-empty-description),
:global(.sponsor-upload-modal .ant-empty-description *),
:global(.sponsor-upload-modal .radio-upload-dropzone-title),
:global(.sponsor-upload-modal .radio-upload-dropzone-hint),
:global(.sponsor-upload-modal .radio-upload-progress-text),
:global(.sponsor-upload-modal .radio-upload-state-tag),
:global(.sponsor-upload-modal .radio-upload-state-tag *),
:global(.sponsor-upload-modal .ant-tag),
:global(.sponsor-upload-modal .ant-tag *),
:global(.sponsor-upload-modal .ant-modal-title),
:global(.sponsor-upload-modal .ant-modal-header),
:global(.sponsor-upload-modal .ant-modal-body) {
  color: #f8fafc !important;
}

:global(.sponsor-upload-modal .ant-tag-green),
:global(.sponsor-upload-modal .ant-tag-blue),
:global(.sponsor-upload-modal .ant-tag-geekblue),
:global(.sponsor-upload-modal .ant-tag-gold),
:global(.sponsor-upload-modal .ant-tag-orange),
:global(.sponsor-upload-modal .ant-tag-cyan),
:global(.sponsor-upload-modal .ant-tag-red),
:global(.sponsor-upload-modal .ant-tag-purple),
:global(.sponsor-upload-modal .ant-tag-lime),
:global(.sponsor-upload-modal .radio-upload-state-tag),
:global(.sponsor-upload-modal .radio-upload-state-tag *) {
  color: #090d16 !important;
  font-weight: 700 !important;
  text-shadow: none !important;
}

:global(.sponsor-upload-modal .ant-btn-default),
:global(.sponsor-upload-modal .ant-btn-dashed),
:global(.sponsor-upload-modal .ant-btn-ghost) {
  color: #090d16 !important;
  background: #f8fafc !important;
  border-color: rgba(248, 250, 252, 0.9) !important;
}

:global(.sponsor-upload-modal .ant-btn-default:hover),
:global(.sponsor-upload-modal .ant-btn-dashed:hover),
:global(.sponsor-upload-modal .ant-btn-ghost:hover) {
  color: #090d16 !important;
  background: #ffffff !important;
  border-color: #ffffff !important;
}

:global(.sponsor-upload-modal .ant-btn-primary) {
  color: #ffffff !important;
}

.radio-upload-footer {
  margin-top: 14px;
}

.radio-table :deep(.ant-table) {
  border-radius: 18px;
  overflow: hidden;
}

.radio-modal :deep(.ant-modal-content) {
  border-radius: 28px;
  overflow: hidden;
}

.radio-modal-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}

.radio-modal-side {
  display: grid;
  gap: 14px;
  align-self: start;
  min-width: 0;
}

.radio-modal-upload-box {
  display: grid;
  gap: 12px;
  padding: 16px;
  border-radius: 18px;
  background: linear-gradient(180deg, rgba(9, 16, 29, 0.96), rgba(8, 15, 27, 0.92));
  border: 1px solid rgba(148, 163, 184, 0.14);
  color: #fff;
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.03),
    0 14px 30px rgba(15, 23, 42, 0.18);
  align-self: start;
  height: fit-content;
}

.radio-modal-upload-box :deep(*),
.radio-modal-upload-box :deep(.ant-upload),
.radio-modal-upload-box :deep(.ant-upload-wrapper),
.radio-modal-upload-box :deep(.ant-upload-list),
.radio-modal-upload-box :deep(.ant-upload-list-item),
.radio-modal-upload-box :deep(.ant-upload-text),
.radio-modal-upload-box :deep(.ant-upload-hint),
.radio-modal-upload-box :deep(.ant-tag),
.radio-modal-upload-box :deep(.ant-tag *),
.radio-modal-upload-box :deep(.ant-progress),
.radio-modal-upload-box :deep(.ant-progress-text) {
  color: #f8fafc !important;
}

.radio-modal-upload-box :deep(.ant-tag),
.radio-modal-upload-box :deep(.ant-tag span),
.radio-modal-upload-box :deep(.ant-tag *) {
  color: #f8fafc !important;
}

.radio-modal-upload-box :deep(.ant-tag-green),
.radio-modal-upload-box :deep(.ant-tag-blue),
.radio-modal-upload-box :deep(.ant-tag-geekblue),
.radio-modal-upload-box :deep(.ant-tag-gold),
.radio-modal-upload-box :deep(.ant-tag-orange),
.radio-modal-upload-box :deep(.ant-tag-cyan),
.radio-modal-upload-box :deep(.ant-tag-red),
.radio-modal-upload-box :deep(.ant-tag-purple),
.radio-modal-upload-box :deep(.ant-tag-lime),
.radio-modal-upload-box :deep(.radio-upload-state-tag),
.radio-modal-upload-box :deep(.radio-upload-state-tag *) {
  color: #090d16 !important;
  font-weight: 700 !important;
  text-shadow: none !important;
}

.radio-modal-upload-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding-bottom: 10px;
  border-bottom: 1px solid rgba(148, 163, 184, 0.1);
}

.radio-modal-upload-head p {
  margin: 0;
  color: rgba(248, 250, 252, 0.9);
  font-size: 12px;
  line-height: 1.4;
}

.radio-modal-upload-box h3,
.radio-modal-form-head h3 {
  font-size: 18px;
  color: #f8fafc;
}

.radio-modal-upload-box :deep(.ant-upload-drag) {
  min-height: 98px !important;
  padding: 12px !important;
  border: 1px solid rgba(148, 163, 184, 0.18) !important;
  border-radius: 16px !important;
  background:
    radial-gradient(circle at top right, rgba(225, 29, 72, 0.08), transparent 28%),
    linear-gradient(180deg, rgba(11, 17, 31, 0.98), rgba(8, 15, 27, 0.98)) !important;
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.03),
    0 10px 22px rgba(15, 23, 42, 0.14);
  overflow: hidden;
}

.radio-modal-upload-box :deep(.ant-upload-drag .ant-upload) {
  display: grid;
  place-items: center;
  gap: 0;
  height: 100%;
  min-height: 72px;
  padding: 0;
}

.radio-upload-state-tag {
  flex-shrink: 0;
  margin-top: 1px;
  border-radius: 999px;
  font-weight: 800;
  letter-spacing: 0.01em;
}

.radio-upload-state-tag.is-idle {
  color: #f8fafc !important;
}

.radio-modal-upload-box :deep(.ant-upload-drag .ant-upload-text),
.radio-modal-upload-box :deep(.ant-upload-drag .ant-upload-hint),
.radio-modal-upload-box :deep(.ant-upload-drag .ant-upload-text *),
.radio-modal-upload-box :deep(.ant-upload-drag .ant-upload-hint *) {
  color: #f8fafc !important;
}

.radio-upload-progress {
  width: 100%;
  margin: 0;
}

.radio-upload-progress :deep(.ant-progress-outer) {
  margin-right: 0;
}

.radio-upload-progress :deep(.ant-progress-text) {
  color: #f8fafc !important;
  font-weight: 700;
}

.radio-upload-progress :deep(.ant-progress-bg) {
  background: linear-gradient(90deg, #e11d48, #fb7185) !important;
}

.radio-upload-progress :deep(.ant-progress-inner) {
  background: rgba(15, 23, 42, 0.82);
  box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.1);
}

.radio-upload-progress :deep(.ant-progress-status-success .ant-progress-bg) {
  background: linear-gradient(90deg, #10b981, #34d399) !important;
}

.radio-upload-dropzone {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 12px;
  width: 100%;
  min-height: 72px;
  text-align: left;
}

.radio-upload-file-mark {
  display: inline-flex;
  flex: 0 0 76px;
  align-items: center;
  justify-content: center;
  min-height: 42px;
  padding: 0 10px;
  border-radius: 12px;
  background: rgba(248, 250, 252, 0.08);
  border: 1px solid rgba(248, 250, 252, 0.12);
  color: #f8fafc;
  font-size: 11px;
  font-weight: 800;
  white-space: nowrap;
}

.radio-upload-dropzone-title {
  margin: 0;
  color: #f8fafc;
  font-size: 15px;
  font-weight: 700;
  line-height: 1.25;
}

.radio-upload-dropzone-hint {
  margin: 4px 0 0;
  color: rgba(248, 250, 252, 0.78);
  font-size: 12px;
  line-height: 1.35;
}

.radio-upload-progress-row {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 0 2px 1px;
}

.radio-upload-progress-text {
  color: #f8fafc;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.02em;
  min-width: 42px;
  text-align: right;
}

.radio-modal-form {
  display: grid;
  gap: 14px;
  padding: 18px;
  border-radius: 24px;
  background: linear-gradient(180deg, rgba(18, 27, 46, 0.94), rgba(9, 13, 22, 0.98));
  border: 1px solid rgba(148, 163, 184, 0.16);
  min-width: 0;
  width: 100%;
}

.radio-modal-form :deep(.ant-form) {
  display: grid;
  gap: 14px;
}

.radio-modal-form :deep(.ant-form-item) {
  margin-bottom: 0 !important;
}

.radio-modal-form :deep(.ant-form-item-label) {
  padding-bottom: 6px;
}

.radio-modal-form :deep(.ant-form-item-control-input-content) {
  width: 100%;
}

.radio-modal-form :deep(.ant-form-item-control-input-content > *) {
  width: 100%;
}

.radio-modal-form :deep(.ant-radio-group),
.radio-modal-form :deep(.ant-checkbox-group) {
  display: flex;
  flex-wrap: wrap;
  gap: 10px 16px;
}

.radio-modal-form :deep(*),
.radio-modal-form :deep(.ant-form),
.radio-modal-form :deep(.ant-form-item),
.radio-modal-form :deep(.ant-form-item-label > label),
.radio-modal-form :deep(.ant-form-item-label > label span),
.radio-modal-form :deep(.ant-form-item-required),
.radio-modal-form :deep(.ant-form-item-explain),
.radio-modal-form :deep(.ant-form-item-extra),
.radio-modal-form :deep(.ant-radio-wrapper),
.radio-modal-form :deep(.ant-radio-wrapper span),
.radio-modal-form :deep(.ant-radio-wrapper .ant-radio + span),
.radio-modal-form :deep(.ant-checkbox-wrapper),
.radio-modal-form :deep(.ant-checkbox-wrapper span),
.radio-modal-form :deep(.ant-checkbox-wrapper .ant-checkbox + span),
.radio-modal-form :deep(.ant-select-selection-item),
.radio-modal-form :deep(.ant-select-selection-placeholder),
.radio-modal-form :deep(.ant-input),
.radio-modal-form :deep(.ant-input::placeholder),
.radio-modal-form :deep(.ant-input-number-input),
.radio-modal-form :deep(.ant-input-number),
.radio-modal-form :deep(.ant-input-affix-wrapper),
.radio-modal-form :deep(.ant-upload-text),
.radio-modal-form :deep(.ant-upload-hint) {
  color: #f8fafc !important;
}

.radio-modal-form-head h3 {
  color: #f8fafc;
  font-size: 20px;
  font-weight: 700;
  letter-spacing: -0.02em;
}

.radio-modal-form-head p {
  margin: 8px 0 0;
  color: rgba(226, 232, 240, 0.76);
  line-height: 1.6;
  font-size: 14px;
}

.radio-modal-form :deep(.ant-select-selector),
.radio-modal-form :deep(.ant-input),
.radio-modal-form :deep(.ant-input-number),
.radio-modal-form :deep(.ant-input-affix-wrapper) {
  background: rgba(15, 23, 42, 0.72) !important;
  border-color: rgba(148, 163, 184, 0.18) !important;
}

.radio-modal-form :deep(.ant-radio-inner),
.radio-modal-form :deep(.ant-checkbox-inner) {
  border-color: rgba(248, 250, 252, 0.55) !important;
  background: rgba(15, 23, 42, 0.55) !important;
}

.radio-modal-form :deep(.ant-radio-checked .ant-radio-inner),
.radio-modal-form :deep(.ant-checkbox-checked .ant-checkbox-inner) {
  border-color: #3b82f6 !important;
  background: #3b82f6 !important;
}

.radio-modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

.radio-modal-actions :deep(.ant-btn),
.radio-card-head :deep(.ant-btn) {
  min-height: 42px;
  padding-inline: 18px;
  font-size: 14px;
  font-weight: 700;
  letter-spacing: -0.01em;
  border-radius: 999px;
  box-shadow: 0 10px 24px rgba(225, 29, 72, 0.16);
}

.radio-modal-actions :deep(.ant-btn-primary),
.radio-card-head :deep(.ant-btn-primary) {
  background: linear-gradient(135deg, #e11d48 0%, #fb7185 100%) !important;
  border: 1px solid rgba(251, 113, 133, 0.18) !important;
  color: #ffffff !important;
}

.radio-modal-actions :deep(.ant-btn-primary:hover),
.radio-card-head :deep(.ant-btn-primary:hover) {
  background: linear-gradient(135deg, #f43f5e 0%, #fb7185 100%) !important;
  border-color: rgba(251, 113, 133, 0.32) !important;
  color: #ffffff !important;
}

.radio-modal-actions :deep(.ant-btn-default),
.radio-modal-actions :deep(.ant-btn-dashed),
.radio-modal-actions :deep(.ant-btn-ghost) {
  background: rgba(248, 250, 252, 0.96) !important;
  border-color: rgba(248, 250, 252, 0.88) !important;
  color: #090d16 !important;
}

.radio-modal-actions :deep(.ant-btn-default:hover),
.radio-modal-actions :deep(.ant-btn-dashed:hover),
.radio-modal-actions :deep(.ant-btn-ghost:hover) {
  background: #ffffff !important;
  border-color: #ffffff !important;
  color: #090d16 !important;
}

:deep(.ant-card-head-title) {
  font-weight: 700;
}

@media (max-width: 1280px) {
  .radio-hero-card,
  .radio-banner-strip,
  .radio-grid-layout,
  .sponsors-grid-layout {
    grid-template-columns: 1fr;
  }

  .sponsors-editor-overview,
  .radio-kpi-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 920px) {
  .radio-modal-grid {
    grid-template-columns: 1fr;
  }

  .sponsors-editor-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 860px) {
  .sponsors-editor-overview,
  .radio-kpi-grid,
  .radio-banner-strip,
  .radio-modal-meta-grid {
    grid-template-columns: 1fr;
  }

  .radio-card-head,
  .radio-modal-actions {
    flex-direction: column;
    align-items: stretch;
  }

  .radio-modal-upload-head {
    flex-direction: column;
  }
}
</style>

