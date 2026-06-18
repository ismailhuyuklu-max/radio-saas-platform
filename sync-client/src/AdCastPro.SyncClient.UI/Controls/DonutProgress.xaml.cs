using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Media.Effects;
using System.Windows.Shapes;

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

    public static readonly DependencyProperty HideValueProperty = DependencyProperty.Register(
        nameof(HideValue), typeof(bool), typeof(DonutProgress),
        new PropertyMetadata(false, OnVisualChanged));

    /// <summary>Ortadaki yüzde/alt metni gizler — üstüne özel içerik (ikon vb.) koymak için.</summary>
    public bool HideValue
    {
        get => (bool)GetValue(HideValueProperty);
        set => SetValue(HideValueProperty, value);
    }

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

    private static readonly Brush TickOffBrush =
        new SolidColorBrush(Color.FromRgb(0x18, 0x27, 0x3E));

    private void Redraw()
    {
        if (Arc == null) return;

        var pct = Math.Max(0, Math.Min(100, Percent));
        BigText.Text = $"%{pct:0}";
        SubTextBlock.Text = SubText ?? string.Empty;
        BigText.Visibility = HideValue ? Visibility.Collapsed : Visibility.Visible;
        SubTextBlock.Visibility = HideValue ? Visibility.Collapsed : Visibility.Visible;
        Arc.Stroke = RingColor;

        var ringColor = RingColor is SolidColorBrush rscb ? rscb.Color : Color.FromRgb(0x19, 0xC3, 0x7D);
        if (TickGlow != null) TickGlow.Color = ringColor;
        Arc.Effect = new DropShadowEffect { Color = ringColor, BlurRadius = 10, ShadowDepth = 0, Opacity = 0.45 };

        double w = ActualWidth, h = ActualHeight;
        if (w <= 0 || h <= 0) return;

        double cx = w / 2, cy = h / 2;
        double margin = 7;
        double ro = Math.Min(w, h) / 2 - margin;            // dis tick yaricapi
        if (ro <= 6) return;
        double tickLen = Math.Clamp(ro * 0.20, 5, 14);
        double ri = ro - tickLen;                           // ic tick yaricapi
        double tickW = Math.Clamp(ro * 0.05, 2, 4);
        int n = (int)Math.Clamp(ro * 0.95, 32, 64);         // tick sayisi (boyuta gore)

        TickBg.Children.Clear();
        TickFg.Children.Clear();

        for (int i = 0; i < n; i++)
        {
            double frac = i / (double)n;                    // 0 = tepe
            double ang = frac * 360.0;
            double rad = ang * Math.PI / 180.0;
            double s = Math.Sin(rad), c = Math.Cos(rad);
            bool on = frac * 100.0 <= pct + 0.0001;

            var line = new Line
            {
                X1 = cx + ro * s,
                Y1 = cy - ro * c,
                X2 = cx + ri * s,
                Y2 = cy - ri * c,
                StrokeThickness = tickW,
                StrokeStartLineCap = PenLineCap.Round,
                StrokeEndLineCap = PenLineCap.Round,
                Stroke = on ? RingColor : TickOffBrush,
            };
            if (on) TickFg.Children.Add(line); else TickBg.Children.Add(line);
        }

        // Ticklerin hemen icinde ince, surekli vurgu arc'i.
        double rArc = ri - tickW;
        double angle = pct / 100.0 * 360.0;
        if (rArc <= 0 || angle <= 0.01)
        {
            Arc.Data = null;
            return;
        }
        if (angle >= 360) angle = 359.999;

        double aRad = angle * Math.PI / 180.0;
        var start = new Point(cx, cy - rArc);
        var end = new Point(cx + rArc * Math.Sin(aRad), cy - rArc * Math.Cos(aRad));
        bool largeArc = angle > 180;
        var fig = new PathFigure { StartPoint = start, IsClosed = false, IsFilled = false };
        fig.Segments.Add(new ArcSegment(end, new Size(rArc, rArc), 0, largeArc, SweepDirection.Clockwise, true));
        Arc.Data = new PathGeometry(new[] { fig });
    }
}
