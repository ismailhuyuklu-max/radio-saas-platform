using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Media.Effects;

namespace AdCastPro.SyncClient.UI.Controls;

/// <summary>
/// Dairesel ilerleme halkasi (donut) — ortada yuzde + alt etiket.
/// Yayin Hazirlik Durumu ve Indirme Durumu kartlari icin.
/// </summary>
public partial class DonutProgress : UserControl
{
    public DonutProgress()
    {
        InitializeComponent();
    }

    public static readonly DependencyProperty PercentProperty = DependencyProperty.Register(
        nameof(Percent), typeof(double), typeof(DonutProgress),
        new PropertyMetadata(0d, OnVisualChanged));

    public static readonly DependencyProperty RingColorProperty = DependencyProperty.Register(
        nameof(RingColor), typeof(Brush), typeof(DonutProgress),
        new PropertyMetadata(new SolidColorBrush(Color.FromRgb(0x10, 0xB9, 0x81)), OnVisualChanged));

    public static readonly DependencyProperty SubText2Property = DependencyProperty.Register(
        nameof(SubText), typeof(string), typeof(DonutProgress),
        new PropertyMetadata(string.Empty, OnVisualChanged));

    public double Percent
    {
        get => (double)GetValue(PercentProperty);
        set => SetValue(PercentProperty, value);
    }

    public Brush RingColor
    {
        get => (Brush)GetValue(RingColorProperty);
        set => SetValue(RingColorProperty, value);
    }

    public string SubText
    {
        get => (string)GetValue(SubText2Property);
        set => SetValue(SubText2Property, value);
    }

    private static void OnVisualChanged(DependencyObject d, DependencyPropertyChangedEventArgs e)
        => ((DonutProgress)d).Redraw();

    private void OnSizeChanged(object sender, SizeChangedEventArgs e) => Redraw();

    private void Redraw()
    {
        if (Arc == null) return;

        var pct = Math.Max(0, Math.Min(100, Percent));
        BigText.Text = $"%{pct:0}";
        SubTextBlock.Text = SubText ?? string.Empty;
        Arc.Stroke = RingColor;

        // Halka rengine uygun yumuşak parlama — daha profesyonel, modern görünüm.
        if (RingColor is SolidColorBrush scb)
        {
            Arc.Effect = new DropShadowEffect
            {
                Color = scb.Color,
                BlurRadius = 16,
                ShadowDepth = 0,
                Opacity = 0.55,
            };
        }

        double w = ActualWidth, h = ActualHeight;
        if (w <= 0 || h <= 0) return;

        const double thickness = 14;
        double margin = 9;
        double cx = w / 2, cy = h / 2;
        double r = Math.Min(w, h) / 2 - thickness / 2 - margin;
        if (r <= 0) return;

        // 0% -> hicbir arc; 100% -> tam daire (359.9 ile cizilir).
        double angle = pct / 100.0 * 360.0;
        if (angle <= 0.01)
        {
            Arc.Data = null;
            return;
        }
        if (angle >= 360) angle = 359.999;

        double rad = angle * Math.PI / 180.0;
        var start = new Point(cx, cy - r);                       // tepe (12 yonu)
        var end = new Point(cx + r * Math.Sin(rad), cy - r * Math.Cos(rad));
        bool largeArc = angle > 180;

        var fig = new PathFigure { StartPoint = start, IsClosed = false, IsFilled = false };
        fig.Segments.Add(new ArcSegment(end, new Size(r, r), 0, largeArc, SweepDirection.Clockwise, true));
        Arc.Data = new PathGeometry(new[] { fig });
    }
}
