using Optimus.Domain.Common;

namespace Optimus.Domain.Entities;

public class Region : BaseEntity
{
    public string Code { get; set; } = string.Empty;
    public string Name { get; set; } = string.Empty;
    public ICollection<Province> Provinces { get; set; } = new List<Province>();
}

public class Province : BaseEntity
{
    public Guid RegionId { get; set; }
    public Region Region { get; set; } = null!;
    public string Code { get; set; } = string.Empty;
    public string Name { get; set; } = string.Empty;
    public ICollection<City> Cities { get; set; } = new List<City>();
}

public class City : BaseEntity
{
    public Guid ProvinceId { get; set; }
    public Province Province { get; set; } = null!;
    public string Code { get; set; } = string.Empty;
    public string Name { get; set; } = string.Empty;
    public ICollection<Barangay> Barangays { get; set; } = new List<Barangay>();
}

public class Barangay : BaseEntity
{
    public Guid CityId { get; set; }
    public City City { get; set; } = null!;
    public string Code { get; set; } = string.Empty;
    public string Name { get; set; } = string.Empty;
}
